create table if not exists public.pd_docs_ia (
  id uuid primary key default gen_random_uuid(),
  client_id uuid references public.clients(id) on delete set null,
  product_id uuid,
  origem text not null check (origem in ('campanhas','social')),
  titulo text,
  texto_ia text not null,
  texto_final text,
  estado text not null default 'rascunho' check (estado in ('rascunho','aprovado')),
  criado_por uuid,
  editado_por uuid,
  criado_em timestamptz not null default now(),
  editado_em timestamptz
);
create index if not exists pd_docs_ia_cli_idx on public.pd_docs_ia (client_id, origem, criado_em desc);
alter table public.pd_docs_ia enable row level security;
drop policy if exists pd_docs_ia_equipe on public.pd_docs_ia;
create policy pd_docs_ia_equipe on public.pd_docs_ia for select to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
