-- =====================================================================
-- v112 — FORMAÇÃO das IAs: barra de evolução, 1 prova por dia, 3 boas = apta
-- Pré-requisitos: v94 e v111. Seguro rodar mais de uma vez.
-- =====================================================================
alter table public.pd_incubacao add column if not exists boas_para_apto int  not null default 3;
alter table public.pd_incubacao add column if not exists provas_meta    int  not null default 30;
alter table public.pd_incubacao add column if not exists professor_modo text not null default 'api';  -- 'api' (GPT pela chave) | 'externo' (seu GPT corrige pela API)

alter table public.pd_aulas add column if not exists dia date;
alter table public.pd_aulas drop constraint if exists pd_aulas_modo_check;
alter table public.pd_aulas add constraint pd_aulas_modo_check check (modo in ('aula','incubacao','prova'));

create table if not exists public.pd_formacoes (
  id          uuid primary key default gen_random_uuid(),
  ia_user_id  uuid not null references public.profiles(id) on delete cascade,
  personagem  text not null default 'ia',
  skill_id    text not null,
  ativa       boolean not null default true,
  meta_provas int,                       -- vazio = usa o padrão da incubação (30)
  apta_em     timestamptz,               -- preenchido quando atinge as boas seguidas
  criada_em   timestamptz not null default now(),
  unique (ia_user_id, skill_id)
);
alter table public.pd_formacoes enable row level security;
drop policy if exists pd_form_ler on public.pd_formacoes;
create policy pd_form_ler on public.pd_formacoes for select to authenticated using (ia_user_id = auth.uid() or pd_eh_admin());
drop policy if exists pd_form_adm on public.pd_formacoes;
create policy pd_form_adm on public.pd_formacoes for all to authenticated using (pd_eh_admin()) with check (pd_eh_admin());
