-- =====================================================================
-- Pontodot v174 — PD Wallet: grupos de pagamento criáveis (com cor e ordem).
-- Vale para entradas e saídas, na Casa e na Empresa. Seguro rodar mais de uma vez.
-- Os grupos de fábrica (Com NF, Sem NF, Fixo, Variável, Saldo) continuam existindo no app;
-- uma linha aqui com a mesma `chave` só muda o nome/cor/ordem deles. Grupo novo = chave nova.
-- fin_lancamentos.grupo é texto livre (não há CHECK), então nada muda nos lançamentos.
-- =====================================================================
create table if not exists public.fin_grupos (
  id            uuid primary key default gen_random_uuid(),
  ambiente      text not null check (ambiente in ('casa','empresa')),
  sentido       text not null check (sentido in ('entrada','saida')),
  chave         text not null,                     -- o que fica gravado em fin_lancamentos.grupo
  nome          text not null,
  cor           text not null default '#8e8e93',   -- #rrggbb
  ordem         int  not null default 100,         -- menor = mais prioridade
  oculto        boolean not null default false,    -- esconde um grupo de fábrica sem apagar
  criado_em     timestamptz not null default now(),
  atualizado_em timestamptz not null default now(),
  unique (ambiente, sentido, chave)
);
alter table public.fin_grupos enable row level security;

drop trigger if exists fin_grupos_toca on public.fin_grupos;
create trigger fin_grupos_toca before update on public.fin_grupos for each row execute function public.fin_toca_atualizado();

drop policy if exists fin_grupos_ler on public.fin_grupos;
create policy fin_grupos_ler on public.fin_grupos for select to authenticated using (public.fin_pode(ambiente));
drop policy if exists fin_grupos_esc on public.fin_grupos;
create policy fin_grupos_esc on public.fin_grupos for all to authenticated using (public.fin_pode(ambiente, true)) with check (public.fin_pode(ambiente, true));

-- desfazer: drop table public.fin_grupos;   (os lançamentos não são tocados)
