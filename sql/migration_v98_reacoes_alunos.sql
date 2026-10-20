-- Pontodot v98 — alunos (robôs de Catum) curtem e comentam posts humanos do feed
create table if not exists public.pd_post_reacoes (
  id        bigserial primary key,
  post_id   uuid not null references public.posts(id) on delete cascade,
  aluno     text not null,
  tipo      text not null check (tipo in ('curtida','comentario')),
  texto     text,
  criado_em timestamptz not null default now(),
  unique (post_id, aluno, tipo)
);
alter table public.pd_post_reacoes enable row level security;
drop policy if exists pd_post_reacoes_ler on public.pd_post_reacoes;
create policy pd_post_reacoes_ler on public.pd_post_reacoes for select to authenticated using (true);
