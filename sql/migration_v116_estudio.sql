-- v116 — ESTÚDIO: peças editáveis (posts, stories, capas…) salvas como documento JSON, com miniatura e modelos.
-- Idempotente. Só o servidor escreve (RLS ligada, sem políticas). Miniaturas e exportações no bucket público "pecas".
create table if not exists public.pd_pecas (
  id          uuid primary key default gen_random_uuid(),
  client_id   uuid references public.clients(id) on delete set null,
  task_id     text references public.tasks(id) on delete set null,
  nome        text not null default 'Sem nome',
  formato     jsonb not null default '{"w":1080,"h":1080,"nome":"Post"}'::jsonb,
  doc         jsonb not null default '{}'::jsonb,     -- documento do editor (Fabric JSON)
  thumb_url   text,
  modelo      boolean not null default false,         -- true = serve de modelo para peças novas
  arquivada   boolean not null default false,         -- nada se apaga: arquiva
  criado_por  uuid references public.profiles(id) on delete set null,
  criado_em   timestamptz not null default now(),
  mudado_em   timestamptz not null default now(),
  exportada   jsonb not null default '[]'::jsonb      -- [{nome, url, drive_url, em}]
);
create index if not exists pd_pecas_cli on public.pd_pecas (client_id, arquivada, mudado_em desc);
alter table public.pd_pecas enable row level security;

insert into storage.buckets (id, name, public) values ('pecas', 'pecas', true) on conflict (id) do nothing;

update public.pd_modulos set ativo = true, quarentena = false, descricao = 'Editor visual no navegador: posts, stories, capas e carrosséis com o kit de marca do cliente, banco de mídia e exportação direto para o Drive.'
 where slug = 'editor_post';
