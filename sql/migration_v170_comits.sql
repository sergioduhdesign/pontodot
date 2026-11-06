-- v170 · COMITS: grupos de conversa (humanos + IAs + clientes). Fechar nunca apaga: fica com histórico.
create table if not exists pd_comits (
  id uuid primary key default gen_random_uuid(),
  titulo text not null,
  assunto text,
  status text not null default 'aberto' check (status in ('aberto','fechado')),
  publico boolean not null default false,
  criado_por uuid,            -- pessoa que criou (ou a dona da IA que criou)
  criado_por_ia text,         -- chave da IA, quando foi uma IA que abriu
  fechado_por uuid,
  fechado_em timestamptz,
  created_at timestamptz not null default now(),
  ultima_em timestamptz not null default now()
);
create table if not exists pd_comit_membros (
  comit_id uuid not null references pd_comits(id) on delete cascade,
  tipo text not null check (tipo in ('humano','ia','cliente')),
  ref text not null,          -- id do perfil (humano/cliente) ou chave da IA (nix, zumi...)
  nome text,
  entrou_em timestamptz not null default now(),
  primary key (comit_id, tipo, ref)
);
create table if not exists pd_comit_msgs (
  id bigserial primary key,
  comit_id uuid not null references pd_comits(id) on delete cascade,
  autor_tipo text not null default 'humano',   -- humano | ia | cliente | sistema
  autor_ref text,
  autor_nome text,
  texto text not null,
  importante boolean not null default false,
  created_at timestamptz not null default now()
);
create table if not exists pd_comit_log (
  id bigserial primary key,
  comit_id uuid references pd_comits(id) on delete cascade,
  acao text not null,         -- criou | entrou | fechou | reabriu | publico | privado
  quem text,
  detalhe text,
  em timestamptz not null default now()
);
create index if not exists pd_comit_msgs_idx on pd_comit_msgs(comit_id, id);
create index if not exists pd_comit_membros_ref on pd_comit_membros(ref);
create index if not exists pd_comits_ultima on pd_comits(status, ultima_em desc);
alter table pd_comits enable row level security;          -- só a chave de serviço (PHP) lê e escreve
alter table pd_comit_membros enable row level security;
alter table pd_comit_msgs enable row level security;
alter table pd_comit_log enable row level security;
