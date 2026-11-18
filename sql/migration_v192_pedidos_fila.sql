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
