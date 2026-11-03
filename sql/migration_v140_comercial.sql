-- v140 — COMERCIAL (CRM da casa): prospects, contatos, conversas, lotes e sinais.
-- É o "cliente antes de ser cliente". Quando ganha, vira cliente com um clique (client_id aponta pra ficha).
-- Pode rodar mais de uma vez.

create extension if not exists pgcrypto;

create table if not exists public.pd_lotes (            -- cohort: um filtro de sinais salvo, com nome e motivo
  id          uuid primary key default gen_random_uuid(),
  nome        text not null,
  motivo      text,
  filtro      jsonb not null default '{}'::jsonb,
  criado_por  uuid,
  created_at  timestamptz not null default now()
);

create table if not exists public.pd_sinais (           -- catálogo de sinais que a casa sabe reconhecer
  chave       text primary key,
  nome        text not null,
  descricao   text,
  grupo       text,                                     -- descoberta | perfil | pessoas | contato
  custo       text default 'grátis',
  ativo       boolean not null default false,
  ordem       int default 0
);

create table if not exists public.pd_prospects (        -- a empresa
  id            uuid primary key default gen_random_uuid(),
  nome          text not null,
  site          text,
  dominio       text,
  cidade        text,
  uf            text,
  segmento      text,
  porte         text,
  telefone      text,
  zap           text,
  instagram     text,
  agencia_atual text,
  sinais        jsonb not null default '{}'::jsonb,     -- {chave: valor}
  nota          int,                                    -- 1..100 (encaixe)
  angulo        text,                                   -- a frase específica que abre a conversa
  origem        text,                                   -- planilha | descoberta | indicação | site | manual
  lote_id       uuid references public.pd_lotes(id) on delete set null,
  estagio       text not null default 'novo' check (estagio in ('novo','abordado','engajado','qualificando','proposta','negociando','ganho','perdido')),
  motivo_perda  text,
  resp          text,                                   -- chave de quem cuida (equipe)
  ia            text,                                   -- personagem que conduz (tumi, gigi, raku…)
  client_id     uuid references public.clients(id) on delete set null,
  obs           text,
  criado_por    uuid,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);
create index if not exists pd_prospects_estagio on public.pd_prospects (estagio);
create index if not exists pd_prospects_dominio on public.pd_prospects (dominio);
create index if not exists pd_prospects_lote on public.pd_prospects (lote_id);

create table if not exists public.pd_prospect_contatos ( -- a pessoa
  id            uuid primary key default gen_random_uuid(),
  prospect_id   uuid not null references public.pd_prospects(id) on delete cascade,
  nome          text not null,
  cargo         text,
  email         text,
  email_status  text default 'palpite' check (email_status in ('verificado','arriscado','palpite','invalido')),
  zap           text,
  linkedin      text,
  dono          boolean not null default false,
  principal     boolean not null default false,
  opt_out       boolean not null default false,          -- pediu pra não receber: nunca mais
  created_at    timestamptz not null default now()
);
create index if not exists pd_prospect_contatos_prospect on public.pd_prospect_contatos (prospect_id);
create index if not exists pd_prospect_contatos_email on public.pd_prospect_contatos (email);

create table if not exists public.pd_prospect_conversas ( -- cada mensagem, em qualquer canal
  id            uuid primary key default gen_random_uuid(),
  prospect_id   uuid not null references public.pd_prospects(id) on delete cascade,
  contato_id    uuid references public.pd_prospect_contatos(id) on delete set null,
  canal         text not null default 'email' check (canal in ('email','zap','dm','ligacao','reuniao','nota')),
  direcao       text not null default 'saida' check (direcao in ('saida','entrada')),
  sequencia     int,                                    -- 1, 2, 3 (e-mail da sequência)
  assunto       text,
  corpo         text not null,
  status        text not null default 'rascunho' check (status in ('rascunho','aguardando','aprovada','retida','rejeitada','enviada','recebida','falhou')),
  motivo_retida text,
  intencao      text,                                   -- entrada: interessado | objecao | pergunta | pessoa_errada | nao | opt_out | juridico
  escrito_por   text,                                   -- personagem (tumi, gigi, raku…) ou 'humano'
  aprovado_por  uuid,
  agendada_para timestamptz,
  enviada_em    timestamptz,
  wamid         text,
  created_at    timestamptz not null default now()
);
create index if not exists pd_prospect_conversas_prospect on public.pd_prospect_conversas (prospect_id, created_at desc);
create index if not exists pd_prospect_conversas_status on public.pd_prospect_conversas (status);

-- updated_at automático
create or replace function public.pd_touch_updated_at() returns trigger language plpgsql as $$
begin new.updated_at = now(); return new; end $$;
drop trigger if exists pd_prospects_touch on public.pd_prospects;
create trigger pd_prospects_touch before update on public.pd_prospects for each row execute function public.pd_touch_updated_at();

-- RLS: equipe e admin leem e escrevem; cliente não vê nada daqui
alter table public.pd_lotes enable row level security;
alter table public.pd_sinais enable row level security;
alter table public.pd_prospects enable row level security;
alter table public.pd_prospect_contatos enable row level security;
alter table public.pd_prospect_conversas enable row level security;
do $$
declare t text;
begin
  foreach t in array array['pd_lotes','pd_sinais','pd_prospects','pd_prospect_contatos','pd_prospect_conversas'] loop
    execute format('drop policy if exists %I on public.%I', t || '_staff', t);
    execute format('create policy %I on public.%I for all using (is_staff()) with check (is_staff())', t || '_staff', t);
  end loop;
end $$;

-- os sinais que a casa reconhece (desligados até você ligar; a IA propõe e você confirma)
insert into public.pd_sinais (chave, nome, descricao, grupo, custo, ordem) values
  ('AGENCIA_ATUAL',     'Tem agência',              'Quem cuida do site deles hoje, pelo crédito no rodapé.',                       'perfil',     'grátis', 1),
  ('RODA_META_ADS',     'Anuncia na Meta',          'Pixel da Meta no site: está investindo hoje.',                                  'perfil',     'grátis', 2),
  ('RODA_GOOGLE_ADS',   'Anuncia no Google',        'Tag do Google Ads no site.',                                                    'perfil',     'grátis', 3),
  ('SEM_SITE',          'Sem site',                 'Tem telefone e endereço, não tem site.',                                        'descoberta', 'grátis', 4),
  ('SITE_PARADO',       'Blog parado',              'Começou conteúdo e abandonou.',                                                 'perfil',     'grátis', 5),
  ('SEM_SCHEMA',        'Sem marcação SEO',         'Lacuna técnica que dá pra nomear num e-mail.',                                  'perfil',     'grátis', 6),
  ('SEM_WHATSAPP',      'Sem WhatsApp no site',     'Lead tem que ligar: perde o fora de horário.',                                  'perfil',     'grátis', 7),
  ('TECNOLOGIA',        'Plataforma do site',       'WordPress, Wix, HTML…',                                                         'perfil',     'grátis', 8),
  ('TAMANHO_SITE',      'Tamanho do site',          'Quantas páginas tem.',                                                          'perfil',     'grátis', 9),
  ('CONTRATANDO',       'Contratando',              'Vaga aberta de social media, designer, marketing.',                             'perfil',     'grátis', 10),
  ('LANCAMENTO',        'Lançamento em curso',      'Empreendimento em lançamento ou pré-lançamento no próprio site.',               'perfil',     'grátis', 11),
  ('INSTAGRAM',         'Instagram',                'Perfil encontrado e atividade recente.',                                        'perfil',     'grátis', 12),
  ('AVALIACAO_GOOGLE',  'Nota no Google',           'Boa mas invisível, ou visível mas sofrendo.',                                   'descoberta', 'pago',   13),
  ('POSICAO_GOOGLE',    'Posição no Google',        'Onde aparece na própria busca. 11 a 30 é o ponto doce.',                        'descoberta', 'pago',   14),
  ('CONTATO_ENCONTRADO','Contato encontrado',       'Dono ou decisor achado no próprio site.',                                       'pessoas',    'grátis', 15),
  ('EMAIL_VERIFICADO',  'E-mail verificado',        'Endereço que entrega de verdade.',                                              'contato',    'grátis', 16)
on conflict (chave) do nothing;
