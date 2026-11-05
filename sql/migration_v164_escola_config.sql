-- =====================================================================
-- v164 — CONFIGURAÇÃO DA ESCOLA (caixa no painel) + ORIENTAÇÕES VIGENTES
--   Horários da noite (envio à professora, virada de dia), rodadas, teto
--   por noite; e orientações temporárias que valem para TODAS as IAs
--   (ou só para um cliente), guardadas pelo painel, pelo zap ou pelo chat.
-- =====================================================================
alter table public.pd_incubacao add column if not exists hora_professora   time not null default '20:00';  -- a partir daí a PD manda a aula à professora
alter table public.pd_incubacao add column if not exists hora_virada       time not null default '07:00';  -- de manhã a versão volta para a equipe
alter table public.pd_incubacao add column if not exists max_rodadas_noite int  not null default 3;
alter table public.pd_incubacao add column if not exists teto_noite        numeric(10,2) not null default 10;   -- USD por noite nas aulas de documento
alter table public.pd_incubacao add column if not exists fins_de_semana    boolean not null default true;       -- a professora trabalha no fim de semana?
alter table public.pd_incubacao add column if not exists aula_arte_ligada  boolean not null default false;      -- espelho de criativos (desligado até o Sérgio liberar)

create table if not exists public.pd_orientacoes (
  id uuid primary key default gen_random_uuid(),
  texto text not null,
  escopo text not null default 'todas' check (escopo in ('todas','cliente')),
  client_id uuid references public.clients(id) on delete cascade,
  de date, ate date,
  ativa boolean not null default true,
  via text not null default 'painel',
  criado_por uuid references public.profiles(id) on delete set null,
  criado_em timestamptz not null default now()
);
create index if not exists pd_orientacoes_ativa_idx on public.pd_orientacoes (ativa, de, ate);
alter table public.pd_orientacoes enable row level security;
drop policy if exists pd_orient_equipe on public.pd_orientacoes;
create policy pd_orient_equipe on public.pd_orientacoes for select to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
