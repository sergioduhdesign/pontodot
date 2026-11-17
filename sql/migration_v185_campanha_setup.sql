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
