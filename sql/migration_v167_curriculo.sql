-- v167 — Currículo da escola: pedidos de conteúdo à professora (GPT). Pode rodar de novo.
create table if not exists public.pd_curriculo_pedidos (
  id uuid primary key default gen_random_uuid(),
  curso text not null,
  skill_id text not null,
  titulo text,
  status text not null default 'pedido',        -- pedido | entregue | rejeitado | cancelado
  chamado jsonb not null default '{}'::jsonb,   -- pasta do Drive, controle, enviado_em, envios, erro
  versao int,
  erro text,
  criado_por uuid references public.profiles(id) on delete set null,
  criado_em timestamptz not null default now(),
  entregue_em timestamptz
);
create index if not exists pd_curr_status_idx on public.pd_curriculo_pedidos (status, curso);
alter table public.pd_curriculo_pedidos enable row level security;
drop policy if exists pd_curr_adm on public.pd_curriculo_pedidos;
create policy pd_curr_adm on public.pd_curriculo_pedidos for all to authenticated using (pd_eh_admin()) with check (pd_eh_admin());
