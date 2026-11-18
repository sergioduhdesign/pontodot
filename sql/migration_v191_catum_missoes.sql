-- =====================================================================
-- Pontodot v191 — CATUM: progresso das missões de treino (o Diário).
-- Uma linha por pessoa + missão + ciclo: etapa, entregas escritas e XP.
-- A chave (pessoa, missão, ciclo) impede recompensa em dobro.
-- Não mexe em tarefas reais. Seguro rodar mais de uma vez.
-- =====================================================================
create table if not exists public.catum_missoes (
  user_id       uuid not null references auth.users(id) on delete cascade,
  missao        text not null,
  ciclo         int  not null default 1,
  etapa         int  not null default 0,
  estado        text not null default 'disponível',
  entregas      jsonb not null default '[]'::jsonb,
  xp            int  not null default 0,
  atualizado_em timestamptz not null default now(),
  concluida_em  timestamptz,
  primary key (user_id, missao, ciclo)
);
alter table public.catum_missoes enable row level security;
drop policy if exists catum_missoes_ler on public.catum_missoes;
create policy catum_missoes_ler on public.catum_missoes for select to authenticated using (user_id = auth.uid());
drop policy if exists catum_missoes_esc on public.catum_missoes;
create policy catum_missoes_esc on public.catum_missoes for all to authenticated using (user_id = auth.uid()) with check (user_id = auth.uid());

-- desfazer: drop table public.catum_missoes;
