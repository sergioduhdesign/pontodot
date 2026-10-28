-- v125 — Bancos de imagens no Drive (pastas salvas pra uso no Estúdio) + referências guardadas no acervo
create table if not exists public.pd_bancos_drive (
  id         uuid primary key default gen_random_uuid(),
  nome       text not null,
  folder_id  text not null,
  url        text not null default '',
  client_id  uuid references public.clients(id) on delete cascade,   -- null = banco da casa (todos os clientes)
  criado_por uuid references public.profiles(id) on delete set null,
  em         timestamptz not null default now()
);
create unique index if not exists pd_bancos_drive_pasta on public.pd_bancos_drive (folder_id, coalesce(client_id::text, ''));
alter table public.pd_bancos_drive enable row level security;   -- sem políticas: só o servidor (service role)
-- referências usadas nas derivações entram no acervo com categoria 'referencia' (sem alteração de esquema)
create index if not exists pd_acervo_cat on public.pd_acervo (categoria, em desc);
