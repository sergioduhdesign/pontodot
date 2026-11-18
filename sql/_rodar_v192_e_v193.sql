-- v192 · Pedidos do cliente: fila e fila de prioridades
-- O cliente cria o pedido (vira tarefa em BRIEFING para o Atendimento) e define a ordem em que a equipe pega.
-- A ordem mora numa tabela à parte, porque o cliente não pode alterar a tabela de tarefas.

create table if not exists public.pd_fila (
  task_id        text primary key,
  client_id      uuid not null references public.clients(id) on delete cascade,
  pos            integer not null default 0,
  prioridade     boolean not null default false,
  atualizado_por uuid,
  atualizado_em  timestamptz not null default now()
);
create index if not exists pd_fila_cliente on public.pd_fila (client_id, prioridade desc, pos);
alter table public.pd_fila enable row level security;

drop policy if exists pd_fila_ler on public.pd_fila;
create policy pd_fila_ler on public.pd_fila for select to authenticated
  using (is_staff() or (my_role() = 'client' and client_id = my_client_id()));
drop policy if exists pd_fila_esc on public.pd_fila;
create policy pd_fila_esc on public.pd_fila for all to authenticated
  using (is_staff() or (my_role() = 'client' and client_id = my_client_id()))
  with check (is_staff() or (my_role() = 'client' and client_id = my_client_id()));
grant select, insert, update, delete on public.pd_fila to authenticated;

-- o cliente pede: cria a tarefa em BRIEFING para quem tem a função Atendimento e põe no fim da fila dele
create or replace function public.pd_cliente_pede(p_titulo text, p_detalhe text default null, p_prazo date default null, p_prioridade boolean default false)
returns text language plpgsql security definer set search_path = public as $$
declare nid public.tasks.id%type; cid uuid; r text; np integer;
begin
  if my_role() <> 'client' then raise exception 'Só a conta de cliente cria pedido por aqui.'; end if;
  cid := my_client_id();
  if cid is null then raise exception 'Esta conta não está ligada a um cliente.'; end if;
  if coalesce(trim(p_titulo), '') = '' then raise exception 'Diga o que você precisa.'; end if;
  select team_key into r from public.profiles where active and 'atendimento' = any(funcoes) and team_key is not null order by name limit 1;
  r := coalesce(r, 'JOY');
  nid := next_task_id();
  insert into public.tasks (id, action, client_id, status, resp, project, week, created_by, obs, date_cli)
  values (nid, left(trim(p_titulo), 160), cid, 'BRIEFING', r, 'GERAL', 'ESTA SEMANA', auth.uid(), nullif(left(coalesce(p_detalhe, ''), 800), ''), p_prazo);
  select coalesce(max(pos), 0) + 1 into np from public.pd_fila where client_id = cid;
  insert into public.pd_fila (task_id, client_id, pos, prioridade, atualizado_por) values (nid::text, cid, np, coalesce(p_prioridade, false), auth.uid());
  begin   -- avisa o Atendimento e os admins no sino (se falhar, o pedido vale do mesmo jeito)
    insert into public.notifications (user_id, type, title, body)
    select p.id, 'pedido_cliente', left('Pedido novo de ' || coalesce((select name from public.clients where id = cid), 'cliente'), 140), left(trim(p_titulo), 400)
      from public.profiles p where p.active and (p.role = 'admin' or (p.role = 'team' and 'atendimento' = any(p.funcoes)));
  exception when others then null; end;
  return nid::text;
end $$;
revoke all on function public.pd_cliente_pede(text, text, date, boolean) from public;
grant execute on function public.pd_cliente_pede(text, text, date, boolean) to authenticated;
-- v193 · Frentes de atuação, tempo de produção por tarefa (IA + conferência da pessoa) e cliente coringa
-- Rodar DEPOIS do v192 (pd_fila).

-- 1) a fila passa a guardar a frente e o tempo de TODA tarefa (com ou sem cliente)
alter table public.pd_fila alter column client_id drop not null;
alter table public.pd_fila
  add column if not exists frente       text,
  add column if not exists ref_codigo   text,          -- código do catálogo de tempos (pd_ref_tempos) mais parecido
  add column if not exists est_min      integer,       -- tempo de produção ativo, em minutos
  add column if not exists est_lo       integer,
  add column if not exists est_hi       integer,
  add column if not exists est_fonte    text,          -- 'ia' (sugestão) ou 'pessoa' (conferido por quem faz)
  add column if not exists est_nota     text,
  add column if not exists est_em       timestamptz,
  add column if not exists revisado_por uuid,
  add column if not exists revisado_em  timestamptz;

-- 2) carga de cada frente na casa inteira, sem nomes de cliente (o cliente vê só o tamanho da fila)
create or replace function public.pd_fila_carga()
returns table(frente text, tarefas integer, minutos integer, sem_tempo integer)
language sql stable security definer set search_path = public as $$
  select coalesce(f.frente, 'Sem frente'), count(*)::int, coalesce(sum(f.est_min), 0)::int, (count(*) filter (where f.est_min is null))::int
    from public.tasks t left join public.pd_fila f on f.task_id = t.id::text
   where t.status::text in ('BRIEFING', 'EM ANDAMENTO', 'ALTERAÇÃO', 'BUCHA')
   group by 1
$$;
revoke all on function public.pd_fila_carga() from public;
grant execute on function public.pd_fila_carga() to authenticated;

-- 3) cliente coringa: uma conta de cliente da casa que pode entrar como qualquer cliente, para testar antes de liberar
alter table public.profiles
  add column if not exists coringa      boolean not null default false,
  add column if not exists coringa_casa uuid;
update public.profiles p set coringa = true, coringa_casa = coalesce(p.coringa_casa, p.client_id)
 where p.role::text = 'client'
   and p.client_id in (select id from public.clients where upper(trim(name)) in ('SD', 'STRAT', 'STRATESIGN', 'SD/STRAT') or name ilike 'SD %' or name ilike 'Stratesign%');

create or replace function public.pd_coringa_clientes()
returns table(id uuid, name text, atual boolean, casa boolean)
language sql stable security definer set search_path = public as $$
  select c.id, c.name, c.id = p.client_id, c.id = p.coringa_casa
    from public.clients c, public.profiles p
   where p.id = auth.uid() and p.coringa and p.role::text = 'client'
   order by c.name
$$;
create or replace function public.pd_coringa_entrar(p_client uuid)
returns uuid language plpgsql security definer set search_path = public as $$
declare eu public.profiles%rowtype; alvo uuid;
begin
  select * into eu from public.profiles where id = auth.uid();
  if eu.id is null or not eu.coringa or eu.role::text <> 'client' then raise exception 'Esta conta não é coringa.'; end if;
  alvo := coalesce(p_client, eu.coringa_casa);
  if not exists (select 1 from public.clients where id = alvo) then raise exception 'Cliente não encontrado.'; end if;
  update public.profiles set client_id = alvo where id = auth.uid();
  return alvo;
end $$;
revoke all on function public.pd_coringa_clientes() from public;
revoke all on function public.pd_coringa_entrar(uuid) from public;
grant execute on function public.pd_coringa_clientes() to authenticated;
grant execute on function public.pd_coringa_entrar(uuid) to authenticated;

-- 4) todos recebem o chamado para revisar as próprias tarefas (uma vez)
do $$ begin
  insert into public.notifications (user_id, type, title, body)
  select id, 'revisao_tarefas', 'Revise as suas tarefas', 'Confira a frente e o tempo de cada tarefa aberta. A IA já sugeriu; falta você confirmar. Abra Tarefas e toque em "revisar tempos".'
    from public.profiles where active and role::text in ('admin', 'team');
exception when others then null; end $$;
