-- v118 — ACERVO: histórico de tudo que entra na casa (links habilitados, uploads, exportações) — quem, para quem, quando, link.
-- Idempotente. Só o servidor escreve (RLS ligada, sem políticas).
create table if not exists public.pd_acervo (
  id         bigserial primary key,
  tipo       text not null check (tipo in ('link','upload','exportacao','midia_banco')),
  origem     text not null default 'painel',     -- painel | estudio | banco | tarefa | conversa | oficina
  categoria  text not null default '',           -- banco_fotos | elementos | videos | marca | documento | outro
  client_id  uuid references public.clients(id) on delete set null,
  task_id    text references public.tasks(id) on delete set null,
  nome       text not null default '',
  url        text not null default '',
  mime       text not null default '',
  bytes      bigint not null default 0,
  nota       text not null default '',
  por        uuid references public.profiles(id) on delete set null,
  em         timestamptz not null default now()
);
create index if not exists pd_acervo_cli on public.pd_acervo (client_id, em desc);
create index if not exists pd_acervo_em on public.pd_acervo (em desc);
alter table public.pd_acervo enable row level security;
