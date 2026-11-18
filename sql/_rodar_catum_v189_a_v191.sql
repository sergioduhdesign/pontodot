-- =====================================================================
-- Pontodot v189 — CATUM (mundo em pixel): o rostinho e o controle de cada pessoa.
-- Uma linha por pessoa: o personagem que ela montou e o lado do joystick.
-- Não mexe em mais nada. Seguro rodar mais de uma vez.
-- =====================================================================
create table if not exists public.catum_jogador (
  user_id       uuid primary key references auth.users(id) on delete cascade,
  skin          jsonb not null default '{}'::jsonb,
  lado          text  not null default 'e' check (lado in ('e','d')),   -- joystick à esquerda ou à direita
  atualizado_em timestamptz not null default now()
);
alter table public.catum_jogador enable row level security;

drop policy if exists catum_jogador_ler on public.catum_jogador;
create policy catum_jogador_ler on public.catum_jogador for select to authenticated using (user_id = auth.uid());
drop policy if exists catum_jogador_esc on public.catum_jogador;
create policy catum_jogador_esc on public.catum_jogador for all to authenticated using (user_id = auth.uid()) with check (user_id = auth.uid());

-- desfazer: drop table public.catum_jogador;
-- =====================================================================
-- Pontodot v190 — CATUM: quem está estudando na Escola agora.
-- Devolve só o personagem (IA) e o curso das inscrições ATIVAS — nada privado.
-- Sem esta função, Catum mostra na escola as IAs matriculadas (pd_escola_alunos), sem o curso.
-- Seguro rodar mais de uma vez.
-- =====================================================================
create or replace function public.catum_estudando()
returns table (personagem text, curso_id text, termina_em timestamptz)
language sql stable security definer set search_path = public as $$
  select j.personagem, i.curso_id, max(i.termina_em) as termina_em
    from pd_inscricoes i
    join pd_jeito j on j.user_id = i.user_id
   where i.status = 'ativa' and j.personagem is not null and j.personagem <> ''
   group by j.personagem, i.curso_id;
$$;
revoke all on function public.catum_estudando() from public;
grant execute on function public.catum_estudando() to authenticated;

-- desfazer: drop function public.catum_estudando();
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
