-- =====================================================================
-- Pontodot v189 — COMPOSIÇÃO do pagamento de colaborador (Wallet · Empresa)
-- Um pagamento de equipe é composto: vários clientes/trabalhos, cada um com valor,
-- data e parcelas, mais a divisão do mês em 1 ou 2 pagamentos (ex.: 30/70).
-- Seguro rodar mais de uma vez.
-- =====================================================================
create table if not exists public.fin_composicao (
  id           uuid primary key default gen_random_uuid(),
  ambiente     text not null check (ambiente in ('casa','empresa')),
  colaborador  text not null,
  mes          date not null,
  tipo         text not null default 'item' check (tipo in ('item','divisao')),
  cliente      text,
  valor        numeric(14,2) not null default 0,
  data         date,
  parcela_n    int,
  parcela_de   int,
  obs          text,
  pct          numeric(5,2),        -- só na linha 'divisao': % do 1º pagamento
  dia1         int,
  dia2         int,
  ordem        int not null default 100,
  criado_em    timestamptz not null default now()
);
create index if not exists fin_composicao_busca on public.fin_composicao (ambiente, colaborador, mes);
alter table public.fin_composicao enable row level security;
drop policy if exists fin_composicao_ler on public.fin_composicao;
create policy fin_composicao_ler on public.fin_composicao for select to authenticated using (public.fin_pode(ambiente));
drop policy if exists fin_composicao_esc on public.fin_composicao;
create policy fin_composicao_esc on public.fin_composicao for all to authenticated using (public.fin_pode(ambiente, true)) with check (public.fin_pode(ambiente, true));
-- desfazer: drop table public.fin_composicao;   (os lançamentos não são tocados)
