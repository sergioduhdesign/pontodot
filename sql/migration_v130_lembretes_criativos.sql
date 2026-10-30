-- v130 · Lembretes (zap + plataforma, com recorrência) e copy → tarefa dos criativos
-- Pode rodar mais de uma vez.

create table if not exists public.pd_lembretes (
  id          bigserial primary key,
  user_id     uuid not null references public.profiles(id) on delete cascade,
  texto       text not null,
  proximo     timestamptz not null,                        -- próximo disparo
  recorrencia text not null default 'nenhuma'              -- nenhuma | diaria | dias_uteis | semanal | mensal
              check (recorrencia in ('nenhuma','diaria','dias_uteis','semanal','mensal')),
  hora        text,                                        -- HH:MM (horário de Campinas)
  dow         int,                                         -- 1..7 (seg..dom) p/ semanal
  dom         int,                                         -- 1..31 p/ mensal
  task_id     text,
  via         text not null default 'plataforma',          -- plataforma | zap
  ativo       boolean not null default true,
  ultimo_em   timestamptz,
  criado_em   timestamptz not null default now()
);
create index if not exists pd_lembretes_prox on public.pd_lembretes (ativo, proximo);
create index if not exists pd_lembretes_user on public.pd_lembretes (user_id, ativo);

alter table public.pd_lembretes enable row level security;
drop policy if exists pd_lembretes_dono on public.pd_lembretes;
create policy pd_lembretes_dono on public.pd_lembretes
  for all to authenticated
  using (user_id = auth.uid())
  with check (user_id = auth.uid());

-- copy que já virou tarefa dos criativos (evita criar duas vezes)
alter table public.pd_copys add column if not exists criativo_task_id text;

-- quem recebe as peças depois que a copy fica pronta (team_key: SERGIO, JOY, LUANA, JUAN)
alter table public.app_settings add column if not exists criativo_resp text default 'SERGIO';
