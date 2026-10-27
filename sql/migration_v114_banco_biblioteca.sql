-- v114 — Banco de mídia (Pexels · Unsplash · Pixabay) + Biblioteca da casa com controle de licença.
-- Idempotente. Só o servidor lê/escreve (RLS ligada, sem políticas).
create table if not exists public.pd_biblioteca (
  id         uuid primary key default gen_random_uuid(),
  fonte      text not null,                 -- pexels | unsplash | pixabay | envato | upload
  ext_id     text not null,                 -- id na fonte
  tipo       text not null default 'foto' check (tipo in ('foto','video','audio')),
  titulo     text not null default '',
  autor      text not null default '',
  pagina_url text,                          -- página do item na fonte (prova de origem)
  thumb_url  text,
  media_url  text,
  licenca    text not null default '',      -- texto curto da licença no momento em que foi guardado
  atribuicao text not null default '',      -- o que a fonte pede para creditar, se pedir
  tags       text[] not null default '{}',
  cliente    text,                          -- cliente para o qual foi guardado (opcional)
  salvo_por  uuid references public.profiles(id) on delete set null,
  salvo_em   timestamptz not null default now(),
  unique (fonte, ext_id)
);
create table if not exists public.pd_biblioteca_usos (
  id         uuid primary key default gen_random_uuid(),
  item_id    uuid not null references public.pd_biblioteca(id) on delete cascade,
  cliente    text not null,
  projeto    text not null default '',
  peca       text not null default '',
  nota       text not null default '',      -- ex.: nº do registro de licença (Envato), mídia paga sim/não
  por        uuid references public.profiles(id) on delete set null,
  em         timestamptz not null default now()
);
create index if not exists pd_biblioteca_usos_item on public.pd_biblioteca_usos(item_id);
alter table public.pd_biblioteca enable row level security;
alter table public.pd_biblioteca_usos enable row level security;
