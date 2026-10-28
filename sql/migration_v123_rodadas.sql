-- v123 — Derivações: joinha por peça, rodadas guardadas (voltar numa versão), e o trabalho real da IA contando como aprendizado.
alter table public.pd_pecas add column if not exists avaliacao text;        -- gostei | nao
alter table public.pd_pecas add column if not exists rodada int not null default 0;
create table if not exists public.pd_ia_trabalho (
  id          bigserial primary key,
  ia_user_id  uuid references public.profiles(id) on delete set null,
  personagem  text not null default '',
  client_id   uuid references public.clients(id) on delete set null,
  pauta_id    uuid references public.pd_pautas(id) on delete set null,
  familia     text not null default '',
  rodada      int  not null default 1,
  resultado   text not null default '',   -- derivou | aprovada | erro
  custo_usd   numeric(10,4) not null default 0,
  em          timestamptz not null default now()
);
create index if not exists pd_ia_trabalho_ia on public.pd_ia_trabalho (ia_user_id, em desc);
alter table public.pd_ia_trabalho enable row level security;
