-- v102 — FICHAS COMPLETAS DOS CLIENTES DENTRO DO SUPABASE (a IA lê daqui, sem depender do Drive na hora).
-- Rode INTEIRO no Supabase → SQL Editor → Run. Esperado: "Success". Seguro rodar de novo.
create table if not exists public.client_docs (
  id             uuid primary key default gen_random_uuid(),
  cliente_nome   text not null unique,       -- nome como está na ficha (ex.: MEGA Soluções Contábeis)
  apelidos       text[] not null default '{}',  -- outros nomes pelos quais a equipe chama (Gaspar, Mega…)
  titulo         text,
  texto          text not null,               -- o MD inteiro
  origem         text,                         -- drive:<id do arquivo>
  ativo          boolean not null default true,
  atualizado_em  timestamptz not null default now()
);
alter table public.client_docs enable row level security;
drop policy if exists client_docs_equipe on public.client_docs;
create policy client_docs_equipe on public.client_docs for select to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
-- escrita só pelo servidor (service role, via função sd-ia); nenhuma política de insert/update de propósito.
