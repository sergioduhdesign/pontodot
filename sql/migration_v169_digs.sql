-- v169 · DIG: cada pedido do Tumi vira uma "Dig" com resultado próprio (achar / abordar / aquecer)
create table if not exists pd_digs (
  id uuid primary key default gen_random_uuid(),
  client_id uuid references clients(id) on delete set null,
  titulo text not null,
  briefing text not null,
  modo text not null default 'achar' check (modo in ('achar','abordar','aquecer')),
  jornada text,
  jornada_cfg jsonb not null default '{}'::jsonb,
  quantos int not null default 10,
  status text not null default 'rascunho' check (status in ('rascunho','fila','rodando','pronto','parcial','erro')),
  viabilidade jsonb,
  achados int not null default 0,
  duplicados int not null default 0,
  mensagens int not null default 0,
  lote_id uuid,
  erro text,
  criado_por uuid,
  created_at timestamptz not null default now(),
  iniciado_em timestamptz,
  concluido_em timestamptz
);
create index if not exists pd_digs_cliente_idx on pd_digs(client_id, created_at desc);
alter table pd_digs enable row level security;   -- só a chave de serviço (PHP) lê e escreve
