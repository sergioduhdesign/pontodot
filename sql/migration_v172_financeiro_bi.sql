-- =====================================================================
-- Pontodot v172 — PD Wallet: categoria nos lançamentos (BI) e pasta de
-- contratos/propostas (modelos em texto). Seguro rodar mais de uma vez.
-- =====================================================================
alter table public.fin_lancamentos add column if not exists categoria text;

create table if not exists public.fin_modelos (
  id            uuid primary key default gen_random_uuid(),
  titulo        text not null,
  tipo          text not null default 'proposta' check (tipo in ('proposta','contrato','aditivo','outro')),
  cliente       text,
  texto         text not null default '',
  criado_em     timestamptz not null default now(),
  atualizado_em timestamptz not null default now()
);
alter table public.fin_modelos enable row level security;

drop trigger if exists fin_modelos_toca on public.fin_modelos;
create trigger fin_modelos_toca before update on public.fin_modelos for each row execute function public.fin_toca_atualizado();

drop policy if exists fin_modelos_ler on public.fin_modelos;
create policy fin_modelos_ler on public.fin_modelos for select to authenticated using (public.fin_pode('empresa'));
drop policy if exists fin_modelos_esc on public.fin_modelos;
create policy fin_modelos_esc on public.fin_modelos for all to authenticated using (public.fin_pode('empresa', true)) with check (public.fin_pode('empresa', true));
