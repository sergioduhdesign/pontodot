create table if not exists public.pd_ia_pedidos (
  id uuid primary key default gen_random_uuid(),
  de_user uuid,
  de_ia text,
  para_ia text not null,
  pedido text not null,
  contexto jsonb not null default '{}'::jsonb,
  estado text not null default 'novo' check (estado in ('novo','em_andamento','feito','erro')),
  resposta text,
  criado_em timestamptz not null default now(),
  iniciado_em timestamptz,
  concluido_em timestamptz
);
create index if not exists pd_ia_pedidos_estado_idx on public.pd_ia_pedidos (estado, criado_em);
alter table public.pd_ia_pedidos enable row level security;
drop policy if exists pd_ia_pedidos_equipe on public.pd_ia_pedidos;
create policy pd_ia_pedidos_equipe on public.pd_ia_pedidos for select to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
