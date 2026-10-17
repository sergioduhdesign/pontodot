-- =====================================================================
-- Pontodot v86 — ESCOLA AGÊNTICA: alunos em uso + inscrições nos cursos
-- Seguro rodar mais de uma vez.
-- =====================================================================

-- 1) Quais habitantes têm uma pessoa por trás (só o id do personagem; nada privado)
create or replace function public.pd_escola_alunos()
returns table (personagem text, desde timestamptz)
language sql stable security definer set search_path = public as $$
  select j.personagem, min(coalesce(j.matriculado_em, j.atualizado_em)) as desde
    from pd_jeito j
   where j.personagem is not null and j.personagem <> ''
   group by j.personagem
   order by desde;
$$;
grant execute on function public.pd_escola_alunos() to authenticated;

-- 2) Inscrição da IA de uma pessoa num curso (compromisso com prazo e linha de base)
create table if not exists public.pd_inscricoes (
  id          uuid primary key default gen_random_uuid(),
  user_id     uuid not null references auth.users(id) on delete cascade,
  curso_id    text not null,
  iniciada_em timestamptz not null default now(),
  termina_em  timestamptz not null,
  base        jsonb not null default '{}'::jsonb,     -- onde estava no dia da inscrição
  status      text not null default 'ativa' check (status in ('ativa','concluida','cancelada'))
);
create unique index if not exists pd_inscricoes_ativa_uq
  on public.pd_inscricoes (user_id, curso_id) where status = 'ativa';

alter table public.pd_inscricoes enable row level security;
drop policy if exists pd_insc_ler    on public.pd_inscricoes;
drop policy if exists pd_insc_criar  on public.pd_inscricoes;
drop policy if exists pd_insc_mudar  on public.pd_inscricoes;
create policy pd_insc_ler   on public.pd_inscricoes for select to authenticated using (user_id = auth.uid());
create policy pd_insc_criar on public.pd_inscricoes for insert to authenticated with check (user_id = auth.uid());
create policy pd_insc_mudar on public.pd_inscricoes for update to authenticated using (user_id = auth.uid()) with check (user_id = auth.uid());
