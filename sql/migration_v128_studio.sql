-- v128 — PD · Studio Design: 5 áreas (copys, arte digital, locução e trilha, vídeos, edição gráfica)
-- pasta de referências da casa (criada no Drive do Sérgio) + mestres por área + IA assistindo + copys + estilos + voz

alter table public.app_settings add column if not exists drive_refs_id text;
alter table public.app_settings add column if not exists area_masters jsonb;
update public.app_settings set drive_refs_id = '170Cywn0qlSZzlZzVnTaC5PrEBtUdt3NM' where id = 1 and (drive_refs_id is null or drive_refs_id = '');
update public.app_settings set area_masters = '{"copys":"sumi","digital":"zumi","audio":"raku","video":"taro","grafica":"koda"}'::jsonb where id = 1 and area_masters is null;

-- a IA assiste o humano trabalhando: cada ação relevante vira uma observação (aprendizado em tempo real)
create table if not exists public.pd_observacoes (
  id         bigserial primary key,
  area       text not null default '',          -- copys | digital | audio | video | grafica
  client_id  uuid references public.clients(id) on delete set null,
  peca_id    uuid,
  user_id    uuid references public.profiles(id) on delete set null,   -- o humano
  ia_user_id uuid references public.profiles(id) on delete set null,   -- a IA que assistiu
  personagem text not null default '',
  evento     text not null default '',
  dados      jsonb,
  em         timestamptz not null default now()
);
create index if not exists pd_observacoes_ia on public.pd_observacoes (ia_user_id, em desc);
create index if not exists pd_observacoes_user on public.pd_observacoes (user_id, em desc);
alter table public.pd_observacoes enable row level security;

-- estilos de escrita / direção (da casa ou do cliente), com DNA em texto e referências
create table if not exists public.pd_estilos (
  id         uuid primary key default gen_random_uuid(),
  area       text not null default 'copys',
  nome       text not null,
  resumo     text not null default '',
  dna        text not null default '',         -- como escreve/dirige: regras, ritmo, vocabulário, estrutura
  refs       jsonb,                             -- [{nome, url}]
  client_id  uuid references public.clients(id) on delete cascade,   -- null = da casa
  ativo      boolean not null default true,
  criado_por uuid references public.profiles(id) on delete set null,
  em         timestamptz not null default now()
);
alter table public.pd_estilos enable row level security;

-- copys geradas (briefing + dados + estilos + variantes)
create table if not exists public.pd_copys (
  id         uuid primary key default gen_random_uuid(),
  client_id  uuid references public.clients(id) on delete cascade,
  nome       text not null default 'Copy',
  briefing   jsonb,
  dados      text not null default '',
  links      jsonb,
  estilos    jsonb,
  variantes  jsonb,
  custo_usd  numeric(10,4) not null default 0,
  criado_por uuid references public.profiles(id) on delete set null,
  em         timestamptz not null default now(),
  mudado_em  timestamptz not null default now()
);
create index if not exists pd_copys_cli on public.pd_copys (client_id, mudado_em desc);
alter table public.pd_copys enable row level security;

-- módulo de voz na oficina (locução por IA, no runner)
insert into public.pd_modulos (slug, nome, tipo, descricao, poder, ativo, quarentena, visivel, onde)
values ('voz', 'Locução por IA', 'pipeline', 'Texto vira locução em português (vozes Kokoro) — MP3 em Saída de materiais e no Drive (cliente › MÍDIA › ÁUDIO).', 'executar', true, false, '{admin,team}', 'oficina')
on conflict (slug) do update set ativo = true, quarentena = false, nome = excluded.nome, descricao = excluded.descricao;
