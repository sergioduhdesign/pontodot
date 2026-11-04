create table if not exists public.pd_ia_voto (
  id uuid primary key default gen_random_uuid(),
  client_id uuid not null references public.clients(id) on delete cascade,
  escopo text not null,
  item text not null,
  voto smallint not null check (voto in (-1, 1)),
  user_id uuid,
  criado_em timestamptz not null default now(),
  unique (client_id, escopo, item)
);
alter table public.pd_ia_voto enable row level security;
drop policy if exists pd_ia_voto_equipe on public.pd_ia_voto;
create policy pd_ia_voto_equipe on public.pd_ia_voto for select to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
