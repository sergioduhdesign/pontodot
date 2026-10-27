-- v117 — PAUTA (ping-pong humano ↔ IA no Estúdio) + lições do Estúdio por cliente.
-- Idempotente. Só o servidor lê/escreve (RLS ligada, sem políticas).
create table if not exists public.pd_pautas (
  id            uuid primary key default gen_random_uuid(),
  client_id     uuid not null references public.clients(id) on delete cascade,
  nome          text not null default 'Pauta',
  texto         text not null default '',           -- o texto que o humano traz (legendas, títulos, argumentos)
  briefing      text not null default '',           -- o prompt: "semana de posts, 3 stories/dia, feed intercalado, 1 vídeo…"
  pasta_drive   text,                               -- id da pasta com as imagens (padrão: cliente › MÍDIA)
  kit_status    jsonb not null default '{}'::jsonb, -- {completo:bool, faltam:[...]} no momento da derivação
  principal_id  uuid references public.pd_pecas(id) on delete set null,
  status        text not null default 'rascunho' check (status in ('rascunho','derivando','revisao','aprovada','produzida','erro')),
  rodada        int  not null default 0,
  historico     jsonb not null default '[]'::jsonb, -- [{rodada, feedback, em, por}]
  plano         jsonb not null default '{}'::jsonb, -- última resposta da IA (spec das derivações + raciocínio)
  aprovado      jsonb not null default '{}'::jsonb, -- specs aprovados (viram exemplo da próxima pauta)
  erro          text,
  custo_usd     numeric(10,4) not null default 0,
  criado_por    uuid references public.profiles(id) on delete set null,
  criado_em     timestamptz not null default now(),
  mudado_em     timestamptz not null default now()
);
create index if not exists pd_pautas_cli on public.pd_pautas (client_id, mudado_em desc);
alter table public.pd_pautas enable row level security;

-- lições do Estúdio: o que o humano ensinou (feedback) e o que foi aprovado, por cliente (null = vale para todos)
create table if not exists public.pd_estudio_licoes (
  id          bigserial primary key,
  client_id   uuid references public.clients(id) on delete cascade,
  pauta_id    uuid references public.pd_pautas(id) on delete set null,
  tipo        text not null default 'feedback' check (tipo in ('feedback','aprovacao','regra')),
  texto       text not null,
  ativa       boolean not null default true,
  por         uuid references public.profiles(id) on delete set null,
  em          timestamptz not null default now()
);
create index if not exists pd_estudio_licoes_cli on public.pd_estudio_licoes (client_id, ativa, em desc);
alter table public.pd_estudio_licoes enable row level security;

-- peças ganham papel na pauta
alter table public.pd_pecas add column if not exists pauta_id uuid references public.pd_pautas(id) on delete set null;
alter table public.pd_pecas add column if not exists papel text not null default 'avulsa';      -- avulsa | principal | derivada
alter table public.pd_pecas add column if not exists derivada_tipo text;                        -- carrossel | stories | video
alter table public.pd_pecas add column if not exists spec jsonb;                                -- spec que a IA gerou (antes da edição humana)
alter table public.pd_pecas add column if not exists ordem int not null default 0;
alter table public.pd_pecas add column if not exists roteiro jsonb;                             -- vídeo: capa, boards, legenda, encerramento, estilo
create index if not exists pd_pecas_pauta on public.pd_pecas (pauta_id, ordem);
