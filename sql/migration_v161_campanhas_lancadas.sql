-- Campanhas que a plataforma subiu na Meta (pausadas até um humano dar play)
create table if not exists public.pd_campanhas_lancadas (
  id uuid primary key default gen_random_uuid(),
  client_id uuid references public.clients(id) on delete set null,
  product_id uuid,
  conta text not null,
  campanha_meta_id text not null,
  nome text,
  objetivo text,
  estado text not null default 'pausada' check (estado in ('pausada','ativa','encerrada','erro')),
  plano jsonb not null default '{}'::jsonb,
  meta_ids jsonb not null default '{}'::jsonb,
  doc_id uuid,
  pagina_id text,
  criado_por uuid,
  criado_em timestamptz not null default now(),
  ligada_em timestamptz,
  ligada_por uuid
);
create index if not exists pd_camp_lanc_cli_idx on public.pd_campanhas_lancadas (client_id, criado_em desc);
alter table public.pd_campanhas_lancadas enable row level security;
drop policy if exists pd_camp_lanc_equipe on public.pd_campanhas_lancadas;
create policy pd_camp_lanc_equipe on public.pd_campanhas_lancadas for select to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
