-- =====================================================================
-- Pontodot v62 — MAESTRO, TIMELINE, ALARMES, SITES DE CLIENTES E ZAP
-- Rode UMA vez: Supabase > SQL Editor > New query > cola > Run.
-- Pode rodar de novo sem medo: tudo aqui é "se ainda não existe".
--
--   task_events      a linha do tempo REAL de cada tarefa (quem fez o quê)
--   tasks.batata     com quem está a vez agora (a batata quente)
--   pd_alarmes       aviso que não para de apitar até alguém cuidar
--   pd_debriefings   briefing humano -> plano do maestro -> validação
--   pd_sites         o site de cada cliente (wp / html) e a esteira semanal
--   pd_credenciais   cofre: só o SERVIDOR lê (nem admin vê pelo app)
--   pd_zap           fila de mensagens de WhatsApp
-- =====================================================================

-- ------------------------------------------------ timeline + batata
create table if not exists public.task_events (
  id         bigint generated always as identity primary key,
  task_id    text not null,
  tipo       text not null,   -- briefing | debriefing | validacao | chamado_ia | entrega_ia
                              -- lote | correcao | aprovacao | ajuste | liberado | agendado
                              -- publicado | zap | site | nota
  titulo     text,
  corpo      text,
  quem       text,            -- nome de quem fez (pessoa, "Maestro", "GPT", "Robô")
  quem_tipo  text not null default 'humano'
             check (quem_tipo in ('humano','maestro','produtor','robo','cliente','sistema')),
  ref        jsonb not null default '{}'::jsonb,   -- set_id, item_id, post_id, chamado…
  criado_em  timestamptz not null default now()
);
create index if not exists task_events_task_idx on public.task_events (task_id, criado_em);

alter table public.tasks add column if not exists batata    text;         -- 'humano' | 'maestro' | 'gpt' | 'robo' | 'cliente'
alter table public.tasks add column if not exists batata_de  uuid;        -- se humano: quem
alter table public.tasks add column if not exists batata_em  timestamptz;

-- ------------------------------------------------ alarmes persistentes
create table if not exists public.pd_alarmes (
  id          bigint generated always as identity primary key,
  user_id     uuid references auth.users(id) on delete cascade,  -- null = qualquer um da equipe
  task_id     text,
  tipo        text not null,   -- aprovar_lote | validar_debriefing | erro_publicacao | revisar_entrega | outro
  titulo      text not null,
  corpo       text,
  link        text,
  criado_em   timestamptz not null default now(),
  cuidado_em  timestamptz,
  cuidado_por uuid references auth.users(id) on delete set null
);
create index if not exists pd_alarmes_abertos_idx on public.pd_alarmes (cuidado_em) where cuidado_em is null;

-- ------------------------------------------------ debriefings do maestro
create table if not exists public.pd_debriefings (
  id           uuid primary key default gen_random_uuid(),
  client_id    uuid references public.clients(id) on delete set null,
  criado_por   uuid references auth.users(id) on delete set null,
  origem       text not null default 'chat',       -- chat | tarefa | upload | zap
  briefing     text not null,                      -- o que o humano mandou (texto corrido)
  anexos       jsonb not null default '[]'::jsonb, -- arquivos/links que vieram junto
  debriefing   jsonb,                              -- o plano do maestro: peças, prazos, setor/IA de cada uma
  status       text not null default 'aguardando_validacao'
               check (status in ('rascunho','aguardando_validacao','validado','recusado','executado')),
  validado_por uuid references auth.users(id) on delete set null,
  validado_em  timestamptz,
  tarefas      text[] not null default '{}',       -- ids das tarefas criadas na execução
  criado_em    timestamptz not null default now(),
  atualizado_em timestamptz not null default now()
);

-- ------------------------------------------------ sites de clientes
create table if not exists public.pd_sites (
  client_id     uuid primary key references public.clients(id) on delete cascade,
  tipo          text check (tipo in ('wp','html','bloco')),
  url           text,
  cfg           jsonb not null default '{}'::jsonb,   -- wp: endereço da API; html: pasta no servidor
  esteira       jsonb not null default '{}'::jsonb,   -- {ativa, dia, hora, capa, posts_por_semana}
  seo           jsonb not null default '{}'::jsonb,   -- estratégia viva: palavras, tom, regras
  ativo         boolean not null default false,
  atualizado_por uuid references auth.users(id) on delete set null,
  atualizado_em timestamptz not null default now()
);

-- cofre de credenciais: NINGUÉM lê pelo app (sem policy de select).
-- Grava-se pelo formulário seguro (endpoint do servidor); lê só o servidor.
create table if not exists public.pd_credenciais (
  id         bigint generated always as identity primary key,
  client_id  uuid references public.clients(id) on delete cascade,
  servico    text not null,            -- wordpress | ftp | outro
  rotulo     text,                     -- "WP do site X" (isso o app pode listar)
  usuario    text,
  segredo    text not null,
  criado_por uuid references auth.users(id) on delete set null,
  criado_em  timestamptz not null default now(),
  unique (client_id, servico)
);

-- ------------------------------------------------ fila do zap
create table if not exists public.pd_zap (
  id         bigint generated always as identity primary key,
  para       text not null,            -- telefone com DDI (+55…)
  quem       text,                     -- nome de quem recebe
  client_id  uuid references public.clients(id) on delete set null,
  task_id    text,
  corpo      text not null,
  link       text,
  status     text not null default 'fila' check (status in ('fila','enviado','erro','manual','cancelado')),
  erro       text,
  tentativas int not null default 0,
  criado_em  timestamptz not null default now(),
  enviado_em timestamptz
);
create index if not exists pd_zap_fila_idx on public.pd_zap (status) where status = 'fila';

-- zap de contato: o número de WhatsApp do cliente e de cada pessoa da equipe
alter table public.clients  add column if not exists zap text;
alter table public.profiles add column if not exists zap text;

-- ------------------------------------------------ esteiras (rotina semanal)
create table if not exists public.pd_esteiras (
  id         bigint generated always as identity primary key,
  client_id  uuid references public.clients(id) on delete cascade,
  nome       text not null,                      -- "Social semanal", "Blog do site"
  tipo       text not null default 'social' check (tipo in ('social','site','misto')),
  prompt     text not null,                      -- o briefing que se repete toda semana
  dia        int  not null default 1 check (dia between 1 and 7),   -- 1=segunda
  hora       int  not null default 7 check (hora between 0 and 23), -- Brasília
  ativa      boolean not null default true,
  ultima_em  timestamptz,
  criado_por uuid references auth.users(id) on delete set null,
  criado_em  timestamptz not null default now()
);

-- ------------------------------------------------ relatório diário de campanha
create table if not exists public.pd_relatorios (
  id         bigint generated always as identity primary key,
  client_id  uuid references public.clients(id) on delete cascade,
  dia        date not null,
  corpo      jsonb not null default '{}'::jsonb,  -- números da Meta + leitura
  analise    text,                                -- a leitura estratégica (texto)
  criado_em  timestamptz not null default now(),
  unique (client_id, dia)
);

-- ------------------------------------------------ o que já foi pro site
create table if not exists public.pd_site_posts (
  item_id      uuid primary key,                  -- approval_items.id
  client_id    uuid references public.clients(id) on delete cascade,
  destino      text not null,                     -- wp | html | bloco
  external_id  text,                              -- id do post no WP
  url          text,
  status       text not null default 'publicado' check (status in ('publicado','erro')),
  erro         text,
  publicado_em timestamptz not null default now()
);

-- ---------------------------------------------------------------------
-- QUEM VÊ O QUÊ
--   equipe (admin/team): timeline, alarmes, debriefings, sites, zap
--   cliente: nada disso (o que é dele chega pela tela de aprovação e pelo zap)
--   credenciais: só o servidor (nenhuma policy de leitura)
-- ---------------------------------------------------------------------
alter table public.task_events    enable row level security;
alter table public.pd_alarmes     enable row level security;
alter table public.pd_debriefings enable row level security;
alter table public.pd_sites       enable row level security;
alter table public.pd_credenciais enable row level security;
alter table public.pd_zap         enable row level security;
alter table public.pd_esteiras    enable row level security;
alter table public.pd_relatorios  enable row level security;
alter table public.pd_site_posts  enable row level security;

do $$
declare t text;
begin
  foreach t in array array['task_events','pd_alarmes','pd_debriefings','pd_sites','pd_zap','pd_esteiras','pd_site_posts'] loop
    execute format('drop policy if exists %I_equipe_ler on public.%I', t, t);
    execute format('create policy %I_equipe_ler on public.%I for select using '
      || '(exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in (''admin'',''team'')))', t, t);
    execute format('drop policy if exists %I_equipe_criar on public.%I', t, t);
    execute format('create policy %I_equipe_criar on public.%I for insert with check '
      || '(exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in (''admin'',''team'')))', t, t);
    execute format('drop policy if exists %I_equipe_mudar on public.%I', t, t);
    execute format('create policy %I_equipe_mudar on public.%I for update using '
      || '(exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in (''admin'',''team''))) with check '
      || '(exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in (''admin'',''team'')))', t, t);
  end loop;
end $$;

-- relatórios: equipe lê; cliente lê SÓ o do próprio cliente (fica aberto na frente)
drop policy if exists pd_relatorios_ler on public.pd_relatorios;
create policy pd_relatorios_ler on public.pd_relatorios for select
  using (exists (select 1 from public.profiles p where p.id = auth.uid()
          and (p.role in ('admin','team') or (p.role = 'client' and p.client_id = pd_relatorios.client_id))));

revoke all on public.task_events, public.pd_alarmes, public.pd_debriefings,
              public.pd_sites, public.pd_zap, public.pd_esteiras, public.pd_relatorios from anon;
grant select, insert, update on public.task_events, public.pd_alarmes,
              public.pd_debriefings, public.pd_sites, public.pd_zap, public.pd_esteiras to authenticated;
revoke all on public.pd_site_posts from anon;
grant select on public.pd_site_posts to authenticated;
grant select on public.pd_relatorios to authenticated;

-- o cofre: nem select pra authenticated — só o servidor (chave de serviço)
revoke all on public.pd_credenciais from anon, authenticated;

-- a timeline não se apaga nem se reescreve pelo app
revoke update, delete on public.task_events from authenticated;

-- conferência: deve devolver  9 | t
select count(*) as tabelas, bool_and(c.relrowsecurity) as rls
  from pg_class c
 where c.relname in ('task_events','pd_alarmes','pd_debriefings','pd_sites','pd_credenciais','pd_zap','pd_esteiras','pd_relatorios','pd_site_posts');
