-- v182 · CAPTAR: públicos por região e interesse, gatilhos de comentário → DM, iscas com consentimento.
-- Tudo só pelo servidor (RLS ligado, sem política): o navegador nunca lê nem grava direto.

create table if not exists public.pd_publicos (
  id          uuid primary key default gen_random_uuid(),
  client_id   uuid references public.clients(id) on delete cascade,
  nome        text not null,
  descricao   text,
  publico     jsonb not null default '{}'::jsonb,   -- mesmo formato do plano da Sumi (+ pontos: [{nome,lat,lng,raio_km}])
  estimativa  jsonb,
  criado_por  uuid,
  created_at  timestamptz not null default now()
);
create index if not exists pd_publicos_cliente on public.pd_publicos (client_id);
alter table public.pd_publicos enable row level security;

create table if not exists public.pd_gatilhos (
  id               uuid primary key default gen_random_uuid(),
  client_id        uuid not null references public.clients(id) on delete cascade,
  palavra          text not null,                    -- palavra que a pessoa comenta (sem acento, minúscula)
  post_id          text,                             -- vazio = qualquer post da conta
  resposta_dm      text not null,                    -- mensagem privada
  resposta_publica text,                             -- opcional: resposta ao comentário
  ativo            boolean not null default true,
  disparos         int not null default 0,
  created_at       timestamptz not null default now()
);
create index if not exists pd_gatilhos_cliente on public.pd_gatilhos (client_id);
alter table public.pd_gatilhos enable row level security;

create table if not exists public.pd_gatilho_log (
  id          uuid primary key default gen_random_uuid(),
  gatilho_id  uuid references public.pd_gatilhos(id) on delete set null,
  client_id   uuid,
  comment_id  text not null unique,                  -- o mesmo comentário nunca dispara duas vezes
  ig_user_id  text,
  username    text,
  texto       text,
  enviado     boolean not null default false,
  erro        text,
  created_at  timestamptz not null default now()
);
alter table public.pd_gatilho_log enable row level security;

create table if not exists public.pd_iscas (
  id            uuid primary key default gen_random_uuid(),
  client_id     uuid not null references public.clients(id) on delete cascade,
  slug          text not null unique,
  titulo        text not null,
  subtitulo     text,
  promessa      text,                                 -- o que a pessoa recebe
  entrega_url   text,                                 -- link do material (aparece depois do cadastro)
  pede_telefone boolean not null default true,
  consentimento text not null default 'Concordo em receber este material e comunicações sobre empreendimentos. Posso sair a qualquer momento.',
  ativo         boolean not null default true,
  capturas      int not null default 0,
  created_at    timestamptz not null default now()
);
alter table public.pd_iscas enable row level security;

create table if not exists public.pd_fornecedor_log (
  id         uuid primary key default gen_random_uuid(),
  fornecedor text not null,
  consulta   text,
  achados    int not null default 0,
  created_at timestamptz not null default now()
);
alter table public.pd_fornecedor_log enable row level security;
