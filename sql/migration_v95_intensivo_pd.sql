-- =====================================================================
-- Pontodot v95 — INTENSIVO PD (painel do admin)
-- Cada intensivo guarda o próprio orçamento diário, dias, tipos de aula e
-- quais IAs participam — nada fixo no código. Depende da v94.
-- Seguro rodar mais de uma vez. Nada aqui apaga ou altera dado existente.
-- =====================================================================

create table if not exists public.pd_intensivos (
  id               uuid primary key default gen_random_uuid(),
  nome             text not null default 'Intensivo PD',
  status           text not null default 'rascunho'
                   check (status in ('rascunho','pronto','ativo','pausado','concluido','encerrado')),
  tipos            jsonb not null default '[]'::jsonb,      -- ids dos tipos de aula escolhidos
  ias              jsonb not null default '[]'::jsonb,      -- user_id de cada IA participante
  dias             int  not null default 7  check (dias between 1 and 60),
  custo_dia_max    numeric(10,2) not null default 10 check (custo_dia_max >= 0),   -- teto diário, em US$
  checagem_horas   int  not null default 24 check (checagem_horas >= 1),
  nota_minima      numeric(5,2) not null default 85 check (nota_minima between 0 and 100),
  sandbox          boolean not null default true,           -- nada sai pra cliente/Meta/site/zap
  calibracao       jsonb not null default '{}'::jsonb,      -- custo por rodada ajustado por tipo
  inicio           date,
  fim              date,
  proxima_checagem timestamptz,
  gasto_total      numeric(10,2) not null default 0,
  criado_por       uuid references public.profiles(id) on delete set null,
  criado_em        timestamptz not null default now(),
  atualizado_em    timestamptz not null default now()
);

create table if not exists public.pd_intensivo_gasto (
  intensivo_id uuid not null references public.pd_intensivos(id) on delete cascade,
  dia          date not null,
  gasto        numeric(10,2) not null default 0,
  aulas        int not null default 0,
  primary key (intensivo_id, dia)
);

alter table public.pd_intensivos      enable row level security;
alter table public.pd_intensivo_gasto enable row level security;

drop policy if exists pd_intensivos_adm on public.pd_intensivos;
create policy pd_intensivos_adm on public.pd_intensivos for all to authenticated
  using (pd_eh_admin()) with check (pd_eh_admin());
drop policy if exists pd_intgasto_adm on public.pd_intensivo_gasto;
create policy pd_intgasto_adm on public.pd_intensivo_gasto for all to authenticated
  using (pd_eh_admin()) with check (pd_eh_admin());

-- QUEM PODE PARTICIPAR: só admin chama. Devolve o mínimo (quem é, qual personagem, apelido).
-- Respostas do questionário e tudo o mais da IA continuam privados (pd_jeito).
create or replace function public.pd_ias_participantes()
returns table (user_id uuid, nome text, personagem text, apelido text, etapa text)
language plpgsql stable security definer set search_path = public as $$
begin
  if not pd_eh_admin() then raise exception 'só admin'; end if;
  return query
    select j.user_id, p.name, j.personagem, j.apelido, j.etapa
      from pd_jeito j join profiles p on p.id = j.user_id
     where j.personagem is not null and p.active is not false
     order by p.name;
end $$;
grant execute on function public.pd_ias_participantes() to authenticated;
