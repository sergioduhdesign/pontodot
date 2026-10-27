-- v115 — FUNDAÇÃO: módulos (capacidades da casa), fila de trabalhos da oficina e log de execução.
-- Idempotente. Só o servidor lê/escreve (RLS ligada, sem políticas).

-- 1) MÓDULOS — cada capacidade que a PD sabe fazer. Quem pode ver/usar, com que poder, e a ficha (o que é, como usar).
create table if not exists public.pd_modulos (
  slug        text primary key,                       -- ex.: video_variacoes, pptx, pdf, deploy_site, banco_midia
  nome        text not null,
  tipo        text not null default 'pipeline' check (tipo in ('editor','pipeline','integracao','conhecimento','agente')),
  descricao   text not null default '',
  ficha       text not null default '',               -- como usar / regras (markdown simples)
  poder       text not null default 'propor' check (poder in ('ler','propor','executar_ok','executar')),
  -- ler: só consulta · propor: sugere e espera gente · executar_ok: executa depois de um OK · executar: executa sozinho
  ativo       boolean not null default false,
  quarentena  boolean not null default true,          -- novo módulo nasce em quarentena: só admin usa até liberar
  visivel     text[] not null default '{admin}',      -- papéis (admin, team, client) ou ids de pessoa
  onde        text not null default 'oficina' check (onde in ('oficina','servidor','navegador','externo')),
  config      jsonb not null default '{}'::jsonb,     -- parâmetros do módulo (sem chaves! chaves ficam no sd-config.php)
  criado_por  uuid references public.profiles(id) on delete set null,
  criado_em   timestamptz not null default now(),
  mudado_em   timestamptz not null default now()
);
alter table public.pd_modulos enable row level security;

-- 2) FILA DE TRABALHOS — tudo que a oficina executa (render, pptx, pdf, deploy, lote de criativos...)
create table if not exists public.pd_trabalhos (
  id          uuid primary key default gen_random_uuid(),
  modulo      text not null references public.pd_modulos(slug),
  titulo      text not null default '',
  status      text not null default 'fila' check (status in ('fila','rodando','pronto','erro','cancelado','aguardando_ok')),
  prioridade  int  not null default 5,                -- 1 = primeiro
  entrada     jsonb not null default '{}'::jsonb,     -- o pedido (spec do trabalho)
  saida       jsonb not null default '{}'::jsonb,     -- arquivos gerados: [{nome, path, url, drive_id, drive_url, bytes, mime}]
  erro        text,
  tentativas  int  not null default 0,
  client_id   uuid references public.clients(id) on delete set null,
  task_id     text references public.tasks(id) on delete set null,
  pedido_por  uuid references public.profiles(id) on delete set null,
  pedido_via  text not null default 'painel',         -- painel | ia | cron
  worker      text,                                   -- quem pegou (ex.: gha-123)
  criado_em   timestamptz not null default now(),
  inicio_em   timestamptz,
  fim_em      timestamptz,
  ok_por      uuid references public.profiles(id) on delete set null,
  ok_em       timestamptz
);
create index if not exists pd_trabalhos_fila on public.pd_trabalhos (status, prioridade, criado_em);
alter table public.pd_trabalhos enable row level security;

-- 3) LOG DE EXECUÇÃO — rastro de tudo que a oficina fez (humano lê, IA lê)
create table if not exists public.pd_oficina_log (
  id          bigserial primary key,
  trabalho_id uuid references public.pd_trabalhos(id) on delete cascade,
  modulo      text,
  evento      text not null,                          -- pegou | passo | pronto | erro | cancelou | ok
  detalhe     text not null default '',
  dados       jsonb not null default '{}'::jsonb,
  em          timestamptz not null default now()
);
create index if not exists pd_oficina_log_trab on public.pd_oficina_log (trabalho_id, em);
alter table public.pd_oficina_log enable row level security;

-- 4) BUCKET da oficina (arquivos gerados ficam aqui até ganhar cópia no Drive)
insert into storage.buckets (id, name, public) values ('oficina', 'oficina', false) on conflict (id) do nothing;

-- 5) MÓDULOS DE FÁBRICA (os que já existem na casa + os que a oficina executa)
insert into public.pd_modulos (slug, nome, tipo, descricao, poder, ativo, quarentena, visivel, onde) values
 ('eco',             'Teste da oficina',          'pipeline',    'Trabalho de teste: a oficina responde com a hora e a versão do ffmpeg. Serve para conferir se o circuito PD → oficina → Drive está vivo.', 'executar', true,  false, '{admin}',      'oficina'),
 ('ffmpeg',          'Vídeo (ffmpeg)',            'pipeline',    'Render de vídeo: cortes, troca de trilha, locução, legenda e formatos. Entrada: arquivos (URLs) + receita.', 'executar_ok', true, true, '{admin,team}', 'oficina'),
 ('video_variacoes', 'Criativos em volume',       'pipeline',    'Um vídeo-base vira N variações trocando trilha, locução, texto e cortes. Cada saída é conferida pela IA antes de entregar.', 'executar_ok', false, true, '{admin,team}', 'oficina'),
 ('pptx',            'Apresentações (PPTX)',      'pipeline',    'Gera PPTX nativo e editável a partir de um roteiro em JSON (slides, títulos, textos, imagens).', 'executar', true, true, '{admin,team}', 'oficina'),
 ('pdf',             'Books, folders e papelaria (PDF)', 'pipeline', 'HTML/CSS vira PDF de impressão (Chromium). Books, revistas, folders, papelaria.', 'executar', true, true, '{admin,team}', 'oficina'),
 ('deploy_site',     'Publicar site (GitHub)',    'pipeline',    'Sobe um site estático num repositório e publica. Precisa do token do GitHub no servidor.', 'executar_ok', false, true, '{admin}', 'oficina'),
 ('banco_midia',     'Banco de mídia',            'integracao',  'Pexels, Unsplash e Pixabay por API oficial + Biblioteca da casa com licença e registro de uso.', 'ler', true, false, '{admin,team}', 'servidor'),
 ('escola',          'Escola das IAs',            'agente',      'Formação das IAs da casa: aulas, provas, lições aprovadas, professor externo.', 'propor', true, false, '{admin,team}', 'servidor'),
 ('meta_ads',        'Meta Ads',                  'integracao',  'Contas de anúncio vinculadas, relatório diário e leitura estratégica.', 'ler', true, false, '{admin,team}', 'externo'),
 ('editor_post',     'Editor de posts e stories', 'editor',      'Editor visual no navegador com o kit de marca do cliente e templates travados. (em construção)', 'executar', false, true, '{admin,team}', 'navegador')
on conflict (slug) do nothing;
