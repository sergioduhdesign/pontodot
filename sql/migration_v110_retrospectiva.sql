-- =====================================================================
-- v110 — RETROSPECTIVA (relatório semanal de social + campanha, com mercado)
-- Rode inteiro no SQL Editor do Supabase. Pode rodar mais de uma vez.
-- =====================================================================

-- Uma linha por cliente: link (token), segmento, quando sai o zap, frequência do BI
create table if not exists public.pd_retro_config (
  client_id       uuid primary key references public.clients(id) on delete cascade,
  token           text not null unique default replace(gen_random_uuid()::text, '-', ''),
  segmento        text not null default 'lancamento' check (segmento in ('lancamento','alto_padrao','medio_padrao','mcmv')),
  ativo           boolean not null default true,
  zap_dia         int  not null default 1 check (zap_dia between 1 and 7),   -- 1 = segunda ... 7 = domingo
  zap_hora        int  not null default 9 check (zap_hora between 0 and 23),
  bi_freq         text not null default 'semanal' check (bi_freq in ('diario','semanal')),
  enviar_cliente  boolean not null default false,      -- começa só com o admin
  destinatarios   text[] not null default '{}',        -- telefones do cliente (quando enviar_cliente = true)
  ultimo_zap_em   timestamptz,
  criado_em       timestamptz not null default now()
);

-- Cada relatório gerado (a "foto" da semana ou do dia)
create table if not exists public.pd_retro_snaps (
  id        bigint generated always as identity primary key,
  client_id uuid not null references public.clients(id) on delete cascade,
  tipo      text not null default 'semanal' check (tipo in ('semanal','diario')),
  ini       date not null,
  fim       date not null,
  dados     jsonb not null,
  criado_em timestamptz not null default now(),
  unique (client_id, tipo, fim)
);
create index if not exists pd_retro_snaps_cli on public.pd_retro_snaps (client_id, tipo, fim desc);

-- Faixas de mercado (públicas e, no futuro, da nossa carteira)
create table if not exists public.pd_mercado (
  id        bigint generated always as identity primary key,
  segmento  text not null,
  metrica   text not null check (metrica in ('cpl','cpm','ctr','freq')),
  p25       numeric not null,
  mediana   numeric not null,
  p75       numeric not null,
  fonte     text,
  base      text not null default 'publico' check (base in ('publico','plataforma')),
  unique (segmento, metrica, base)
);

-- Funil comercial (CRM): alimentado por webhook/entrada manual; vazio = o bloco não aparece
create table if not exists public.pd_retro_funil (
  id        bigint generated always as identity primary key,
  client_id uuid not null references public.clients(id) on delete cascade,
  dados     jsonb not null,
  criado_em timestamptz not null default now()
);
create index if not exists pd_retro_funil_cli on public.pd_retro_funil (client_id, criado_em desc);

-- Só o servidor (chave secreta) lê e escreve; ninguém pelo navegador
alter table public.pd_retro_config enable row level security;
alter table public.pd_retro_snaps  enable row level security;
alter table public.pd_mercado      enable row level security;
alter table public.pd_retro_funil  enable row level security;

-- Referências públicas (ESTIMATIVAS de mercado BR para imobiliário no Meta Ads; revise quando tiver dado próprio)
insert into public.pd_mercado (segmento, metrica, p25, mediana, p75, fonte, base) values
  ('lancamento',   'cpl', 18, 28, 42, 'Estimativa a partir de benchmarks públicos (Leal Mídia, Trafius, Benchmarketing)', 'publico'),
  ('alto_padrao',  'cpl', 40, 55, 70, 'Estimativa a partir de benchmarks públicos', 'publico'),
  ('medio_padrao', 'cpl', 20, 25, 33, 'Estimativa a partir de benchmarks públicos', 'publico'),
  ('mcmv',         'cpl', 10, 13, 17, 'Estimativa a partir de benchmarks públicos', 'publico')
on conflict (segmento, metrica, base) do nothing;

insert into public.pd_mercado (segmento, metrica, p25, mediana, p75, fonte, base)
select s, m.metrica, m.p25, m.mediana, m.p75, 'Estimativa a partir de benchmarks públicos', 'publico'
from unnest(array['lancamento','alto_padrao','medio_padrao','mcmv']) s,
     (values ('cpm', 25, 35, 45), ('ctr', 0.7, 1.0, 1.5), ('freq', 1.2, 1.8, 2.6)) as m(metrica, p25, mediana, p75)
on conflict (segmento, metrica, base) do nothing;
