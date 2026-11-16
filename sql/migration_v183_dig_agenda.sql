-- v183 · DIG PROGRAMADA: uma Dig que roda sozinha de hora em hora, todo dia, toda semana ou todo mês.
-- Só pelo servidor (RLS ligado, sem política). O relógio da casa (pd-worker, a cada 5 min) dispara as que venceram.

create table if not exists public.pd_dig_agenda (
  id            uuid primary key default gen_random_uuid(),
  client_id     uuid not null references public.clients(id) on delete cascade,
  titulo        text not null,
  briefing      text not null,
  modo          text not null default 'achar',          -- achar | abordar | aquecer
  jornada       text,
  jornada_cfg   jsonb not null default '{}'::jsonb,
  quantos       int not null default 10,
  frequencia    text not null default 'semanal',        -- horaria | diaria | semanal | mensal
  hora          int not null default 9,                 -- 0-23 (horário de São Paulo)
  minuto        int not null default 0,                 -- 0-59
  dia_semana    int not null default 1,                 -- 0 = domingo ... 6 = sábado (semanal)
  dia_mes       int not null default 1,                 -- 1-28 (mensal)
  ativo         boolean not null default true,
  proxima_em    timestamptz not null,
  ultima_em     timestamptz,
  ultima_dig_id uuid,
  ultimo_status text,                                   -- resumo da última rodada (ex.: "5 novos" ou o erro)
  rodadas       int not null default 0,
  criado_por    uuid,
  created_at    timestamptz not null default now()
);
create index if not exists pd_dig_agenda_cliente on public.pd_dig_agenda (client_id);
create index if not exists pd_dig_agenda_proxima on public.pd_dig_agenda (ativo, proxima_em);
alter table public.pd_dig_agenda enable row level security;
