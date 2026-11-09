-- =====================================================================
-- Pontodot v176 — SEO dos sites dos clientes: auditorias e propostas (antes/depois).
-- Nada é aplicado sem aprovação; cada mudança guarda o "antes" para desfazer.
-- Só o servidor escreve (service key); a equipe lê. Seguro rodar mais de uma vez.
-- =====================================================================
create table if not exists public.pd_seo_auditorias (
  id         uuid primary key default gen_random_uuid(),
  client_id  uuid not null references public.clients(id) on delete cascade,
  nota       int,
  plugin     text,                         -- yoast | rankmath | aioseo | nenhum
  resumo     jsonb not null default '{}'::jsonb,
  itens      jsonb not null default '[]'::jsonb,
  criado_em  timestamptz not null default now()
);
create index if not exists pd_seo_auditorias_cli on public.pd_seo_auditorias (client_id, criado_em desc);

create table if not exists public.pd_seo_propostas (
  id          uuid primary key default gen_random_uuid(),
  client_id   uuid not null references public.clients(id) on delete cascade,
  tipo        text not null check (tipo in ('post','page')),
  wp_id       bigint not null,
  url         text,
  campo       text not null check (campo in ('titulo_seo','meta','alt')),
  alvo        text,                        -- alt: id da imagem no WP
  antes       text,
  depois      text,
  motivo      text,
  status      text not null default 'proposta' check (status in ('proposta','aplicada','revertida','recusada','erro')),
  erro        text,
  criado_por  uuid,
  criado_em   timestamptz not null default now(),
  aplicado_em timestamptz
);
create index if not exists pd_seo_propostas_cli on public.pd_seo_propostas (client_id, criado_em desc);

alter table public.pd_seo_auditorias enable row level security;
alter table public.pd_seo_propostas  enable row level security;
drop policy if exists pd_seo_aud_ler on public.pd_seo_auditorias;
create policy pd_seo_aud_ler on public.pd_seo_auditorias for select
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
drop policy if exists pd_seo_prop_ler on public.pd_seo_propostas;
create policy pd_seo_prop_ler on public.pd_seo_propostas for select
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
revoke all on public.pd_seo_auditorias, public.pd_seo_propostas from anon;
grant select on public.pd_seo_auditorias, public.pd_seo_propostas to authenticated;

-- desfazer: drop table public.pd_seo_propostas; drop table public.pd_seo_auditorias;
