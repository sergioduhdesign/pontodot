-- =====================================================================
-- Pontodot v188 — PD Wallet: "Onde está o dinheiro hoje" (saldo real por banco/conta).
-- Uma linha por conta (Santander, Nu, caixa físico…), separada por ambiente (casa / empresa).
-- Não mexe em lançamentos. Seguro rodar mais de uma vez.
-- =====================================================================
create table if not exists public.fin_contas (
  id            uuid primary key default gen_random_uuid(),
  ambiente      text not null check (ambiente in ('casa','empresa')),
  nome          text not null,
  valor         numeric(14,2) not null default 0,
  ordem         int  not null default 100,
  criado_em     timestamptz not null default now(),
  atualizado_em timestamptz not null default now()
);
alter table public.fin_contas enable row level security;

drop trigger if exists fin_contas_toca on public.fin_contas;
create trigger fin_contas_toca before update on public.fin_contas for each row execute function public.fin_toca_atualizado();

drop policy if exists fin_contas_ler on public.fin_contas;
create policy fin_contas_ler on public.fin_contas for select to authenticated using (public.fin_pode(ambiente));
drop policy if exists fin_contas_esc on public.fin_contas;
create policy fin_contas_esc on public.fin_contas for all to authenticated using (public.fin_pode(ambiente, true)) with check (public.fin_pode(ambiente, true));

-- desfazer: drop table public.fin_contas;   (os lançamentos não são tocados)
