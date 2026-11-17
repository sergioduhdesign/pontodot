
-- ############ migration_v183_dig_agenda.sql ############
-- v183 · DIG PROGRAMADA: uma Dig que roda sozinha de hora em hora, todo dia, toda semana ou todo mês.
-- Só pelo servidor (RLS ligado, sem política). O relógio da casa (pd-worker, a cada 5 min) dispara as que venceram.

create table if not exists public.pd_dig_agenda (
  id            uuid primary key default gen_random_uuid(),
  client_id     uuid not null references public.clients(id) on delete cascade,
  titulo        text not null,
  briefing      text not null,
  modo          text not null default 'achar',          -- achar | abordar | aquecer
  jornada       text,
  jornada_cfg   jsonb not null default '{}'::jsonb,
  quantos       int not null default 10,
  frequencia    text not null default 'semanal',        -- horaria | diaria | semanal | mensal
  hora          int not null default 9,                 -- 0-23 (horário de São Paulo)
  minuto        int not null default 0,                 -- 0-59
  dia_semana    int not null default 1,                 -- 0 = domingo ... 6 = sábado (semanal)
  dia_mes       int not null default 1,                 -- 1-28 (mensal)
  ativo         boolean not null default true,
  proxima_em    timestamptz not null,
  ultima_em     timestamptz,
  ultima_dig_id uuid,
  ultimo_status text,                                   -- resumo da última rodada (ex.: "5 novos" ou o erro)
  rodadas       int not null default 0,
  criado_por    uuid,
  created_at    timestamptz not null default now()
);
create index if not exists pd_dig_agenda_cliente on public.pd_dig_agenda (client_id);
create index if not exists pd_dig_agenda_proxima on public.pd_dig_agenda (ativo, proxima_em);
alter table public.pd_dig_agenda enable row level security;

-- ############ migration_v185_campanha_setup.sql ############
-- Setup de campanha (Excel enviado pela tarefa ou pela janela Campanhas) — rodar no Supabase (SQL Editor)
create table if not exists public.pd_campanha_setup (
  id uuid primary key default gen_random_uuid(),
  client_id uuid references public.clients(id) on delete cascade,
  task_id text,
  product_id uuid,
  titulo text,
  dados jsonb not null default '{}'::jsonb,
  criado_por uuid,
  criado_em timestamptz not null default now(),
  usado_em timestamptz
);
create index if not exists pd_camp_setup_cli_idx on public.pd_campanha_setup (client_id, criado_em desc);
alter table public.pd_campanha_setup enable row level security;
drop policy if exists pd_camp_setup_equipe on public.pd_campanha_setup;
create policy pd_camp_setup_equipe on public.pd_campanha_setup for select to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));

-- ############ migration_v186_campanha_no_aprovador.sql ############
-- Detalhes da campanha no aprovador (cliente vê e sugere ajuste) — rodar no Supabase (SQL Editor)
alter table public.approval_sets add column if not exists campanha jsonb;
alter table public.approval_sets add column if not exists campanha_ajustes jsonb not null default '[]'::jsonb;

-- Lotes de campanha já enviados: puxa o setup salvo na mesma tarefa (se existir)
do $$ begin
  if to_regclass('public.pd_campanha_setup') is not null then
    update public.approval_sets s set campanha = (
      select c.dados from public.pd_campanha_setup c where c.task_id = s.task_id order by c.criado_em desc limit 1)
    where s.campanha is null and s.task_id is not null
      and exists (select 1 from public.pd_campanha_setup c where c.task_id = s.task_id);
  end if;
end $$;

-- Link do cliente (sem login): lê a campanha só com o token do lote, e só se o lote já foi liberado
create or replace function public.get_campanha_by_token(p_token text) returns jsonb
language sql security definer set search_path = public stable as $$
  select case when s.campanha is null then null
    else jsonb_build_object('campanha', s.campanha, 'ajustes', coalesce(s.campanha_ajustes, '[]'::jsonb)) end
  from public.approval_sets s
  where s.share_token::text = p_token and s.status <> 'rascunho' limit 1;
$$;

-- Cliente sugere ajuste em uma seção (campanha, persona, publicos, anuncios)
create or replace function public.campanha_ajuste_by_token(p_token text, p_secao text, p_texto text, p_guest text default null) returns jsonb
language plpgsql security definer set search_path = public as $$
declare v_id uuid; v_novo jsonb;
begin
  if p_secao not in ('campanha','persona','publicos','anuncios') then raise exception 'seção inválida'; end if;
  if coalesce(btrim(p_texto), '') = '' or length(p_texto) > 2000 then raise exception 'texto inválido'; end if;
  select s.id into v_id from public.approval_sets s where s.share_token::text = p_token and s.status <> 'rascunho' limit 1;
  if v_id is null then raise exception 'link inválido'; end if;
  v_novo := jsonb_build_object('id', gen_random_uuid(), 'secao', p_secao, 'texto', btrim(p_texto),
    'por', left(coalesce(nullif(btrim(p_guest), ''), 'Cliente'), 80), 'em', now(), 'resolvido', false);
  update public.approval_sets set campanha_ajustes = coalesce(campanha_ajustes, '[]'::jsonb) || v_novo where id = v_id;
  return v_novo;
end $$;

grant execute on function public.get_campanha_by_token(text) to anon, authenticated;
grant execute on function public.campanha_ajuste_by_token(text, text, text, text) to anon, authenticated;

-- ############ migration_v187_marca_do_lote.sql ############
-- Identidade do cliente (nome + logo) no link de aprovação — rodar no Supabase (SQL Editor)
create or replace function public.get_marca_by_token(p_token text) returns jsonb
language sql security definer set search_path = public stable as $$
  select jsonb_build_object('nome', c.name, 'logo', c.logo_url)
  from public.approval_sets s join public.clients c on c.id = s.client_id
  where s.share_token::text = p_token and s.status <> 'rascunho' limit 1;
$$;
grant execute on function public.get_marca_by_token(text) to anon, authenticated;

-- ############ migration_v188_fin_contas.sql ############
-- =====================================================================
-- Pontodot v188 — PD Wallet: "Onde está o dinheiro hoje" (saldo real por banco/conta).
-- Uma linha por conta (Santander, Nu, caixa físico…), separada por ambiente (casa / empresa).
-- Não mexe em lançamentos. Seguro rodar mais de uma vez.
-- =====================================================================
create table if not exists public.fin_contas (
  id            uuid primary key default gen_random_uuid(),
  ambiente      text not null check (ambiente in ('casa','empresa')),
  nome          text not null,
  valor         numeric(14,2) not null default 0,
  ordem         int  not null default 100,
  criado_em     timestamptz not null default now(),
  atualizado_em timestamptz not null default now()
);
alter table public.fin_contas enable row level security;

drop trigger if exists fin_contas_toca on public.fin_contas;
create trigger fin_contas_toca before update on public.fin_contas for each row execute function public.fin_toca_atualizado();

drop policy if exists fin_contas_ler on public.fin_contas;
create policy fin_contas_ler on public.fin_contas for select to authenticated using (public.fin_pode(ambiente));
drop policy if exists fin_contas_esc on public.fin_contas;
create policy fin_contas_esc on public.fin_contas for all to authenticated using (public.fin_pode(ambiente, true)) with check (public.fin_pode(ambiente, true));

-- desfazer: drop table public.fin_contas;   (os lançamentos não são tocados)
