-- v147 — Vendas em ESCALA: bases de 30 mil, lotes (cohorts), fila de leitura de sites e funil por SQL.
alter table public.pd_prospects add column if not exists chave text;                 -- dedupe: carteira|domínio  ou  carteira|nome|cidade
alter table public.pd_prospects add column if not exists enriquecido_em timestamptz; -- quando o site foi lido (null = ainda não)
alter table public.pd_prospects add column if not exists uf2 text;
create unique index if not exists pd_prospects_chave_uq on public.pd_prospects (chave) where chave is not null;
create index if not exists pd_prospects_nota on public.pd_prospects (nota desc nulls last);
create index if not exists pd_prospects_fila on public.pd_prospects (lote_id, enriquecido_em) where enriquecido_em is null;
create index if not exists pd_prospects_uf on public.pd_prospects (uf);
create index if not exists pd_prospects_origem on public.pd_prospects (origem);

-- funil por estágio, de qualquer carteira (null = casa, '*' = todas) — conta direto no banco, sem trazer 30 mil linhas
create or replace function public.pd_vendas_funil(p_carteira text default null)
returns jsonb language sql stable security definer set search_path = public as $$
  select coalesce(jsonb_object_agg(estagio, n), '{}'::jsonb) from (
    select estagio, count(*) as n from public.pd_prospects
     where (p_carteira = '*') or (p_carteira is null and carteira_id is null) or (carteira_id::text = p_carteira)
     group by estagio) t;
$$;
revoke all on function public.pd_vendas_funil(text) from public, anon, authenticated;
grant execute on function public.pd_vendas_funil(text) to service_role;

-- estado da leitura do e-mail (último UID visto) — uma linha só
create table if not exists public.pd_vendas_estado (chave text primary key, valor jsonb not null default '{}'::jsonb, atualizado_em timestamptz not null default now());
alter table public.pd_vendas_estado enable row level security;

-- números da caixinha Vendas na home (tudo numa chamada)
create or replace function public.pd_vendas_resumo()
returns jsonb language sql stable security definer set search_path = public as $$
  select jsonb_build_object(
    'total',     (select count(*) from public.pd_prospects),
    'novos',     (select count(*) from public.pd_prospects where estagio = 'novo'),
    'engajados', (select count(*) from public.pd_prospects where estagio in ('engajado','qualificando','proposta','negociando')),
    'ganhos',    (select count(*) from public.pd_prospects where estagio = 'ganho'),
    'caixa',     (select count(*) from public.pd_prospect_conversas where status in ('aguardando','aprovada') and direcao = 'saida'),
    'respostas', (select count(*) from public.pd_prospect_conversas where direcao = 'entrada' and created_at > now() - interval '7 days' and intencao in ('interessado','pergunta','objecao')),
    'carteiras', (select coalesce(jsonb_agg(jsonb_build_object('id', carteira_id, 'leads', n, 'engajados', e) order by n desc), '[]'::jsonb) from (
        select carteira_id, count(*) as n, count(*) filter (where estagio in ('engajado','qualificando','proposta','negociando')) as e from public.pd_prospects group by carteira_id) t));
$$;
revoke all on function public.pd_vendas_resumo() from public, anon, authenticated;
grant execute on function public.pd_vendas_resumo() to service_role;

-- a "casa" é o cliente SD/STRAT: o que estava sem carteira passa a ser dele
update public.pd_prospects set carteira_id = (select id from public.clients where name ilike '%strat%' order by active desc, name limit 1) where carteira_id is null and exists (select 1 from public.clients where name ilike '%strat%');
update public.pd_lotes     set carteira_id = (select id from public.clients where name ilike '%strat%' order by active desc, name limit 1) where carteira_id is null and exists (select 1 from public.clients where name ilike '%strat%');
select 'v147 ok' as resultado, (select string_agg(name, ' | ') from public.clients where name ilike '%strat%' or name ilike 'sd%') as clientes_da_casa;
