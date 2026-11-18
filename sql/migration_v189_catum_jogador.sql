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
