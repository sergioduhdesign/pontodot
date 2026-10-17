-- =====================================================================
-- Pontodot v93 — ESCOLA VIVA: comentários e curtidas de verdade nos posts
-- Os habitantes de Mufun são ficção; quem comenta e curte aqui é gente da casa.
-- Seguro rodar mais de uma vez.
-- =====================================================================

create table if not exists public.pd_escola_comentarios (
  id         uuid primary key default gen_random_uuid(),
  post_key   text not null,                         -- ex.: nami:2026-10-12 (habitante:segunda da semana)
  user_id    uuid not null references auth.users(id) on delete cascade,
  autor      text not null default '',              -- nome no momento do comentário
  texto      text not null check (char_length(texto) between 1 and 600),
  criado_em  timestamptz not null default now()
);
create index if not exists pd_escola_com_post_idx on public.pd_escola_comentarios (post_key, criado_em);

create table if not exists public.pd_escola_curtidas (
  post_key   text not null,
  user_id    uuid not null references auth.users(id) on delete cascade,
  criado_em  timestamptz not null default now(),
  primary key (post_key, user_id)
);

alter table public.pd_escola_comentarios enable row level security;
alter table public.pd_escola_curtidas    enable row level security;

drop policy if exists pd_esc_com_ler   on public.pd_escola_comentarios;
drop policy if exists pd_esc_com_criar on public.pd_escola_comentarios;
drop policy if exists pd_esc_com_apagar on public.pd_escola_comentarios;
create policy pd_esc_com_ler    on public.pd_escola_comentarios for select to authenticated using (true);
create policy pd_esc_com_criar  on public.pd_escola_comentarios for insert to authenticated with check (user_id = auth.uid());
create policy pd_esc_com_apagar on public.pd_escola_comentarios for delete to authenticated
  using (user_id = auth.uid() or exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));

drop policy if exists pd_esc_cur_ler    on public.pd_escola_curtidas;
drop policy if exists pd_esc_cur_criar  on public.pd_escola_curtidas;
drop policy if exists pd_esc_cur_apagar on public.pd_escola_curtidas;
create policy pd_esc_cur_ler    on public.pd_escola_curtidas for select to authenticated using (true);
create policy pd_esc_cur_criar  on public.pd_escola_curtidas for insert to authenticated with check (user_id = auth.uid());
create policy pd_esc_cur_apagar on public.pd_escola_curtidas for delete to authenticated using (user_id = auth.uid());
