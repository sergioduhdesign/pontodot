-- =====================================================================
-- Pontodot v60 — ROBÔ DO LOTE + AGENDAR SOZINHO
-- Rode UMA vez: Supabase > SQL Editor > New query > cola > Run.
-- Pode rodar de novo sem medo: tudo aqui é "se ainda não existe".
--
--   task_drive.robo / robo_em   quem está montando o lote da entrega da
--                               IA agora (trava: um aparelho por tarefa)
--   pd_publicacao               por cliente: agendar sozinho? em quais
--                               contas?
-- =====================================================================

alter table public.task_drive add column if not exists robo    text;
alter table public.task_drive add column if not exists robo_em timestamptz;

create table if not exists public.pd_publicacao (
  client_id        uuid primary key references public.clients(id) on delete cascade,
  agendar_sozinho  boolean not null default false,
  contas           uuid[]  not null default '{}',   -- social_accounts.id
  atualizado_por   uuid references auth.users(id) on delete set null,
  atualizado_em    timestamptz not null default now()
);

-- equipe (admin/team) vê e muda; cliente não vê
alter table public.pd_publicacao enable row level security;
drop policy if exists pd_publicacao_ler    on public.pd_publicacao;
drop policy if exists pd_publicacao_criar  on public.pd_publicacao;
drop policy if exists pd_publicacao_mudar  on public.pd_publicacao;
create policy pd_publicacao_ler on public.pd_publicacao for select
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
create policy pd_publicacao_criar on public.pd_publicacao for insert
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
create policy pd_publicacao_mudar on public.pd_publicacao for update
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
revoke all on public.pd_publicacao from anon;
grant select, insert, update on public.pd_publicacao to authenticated;

-- conferência: deve devolver  2 | t | (o tipo da data das peças — me mande essa linha)
select (select count(*) from information_schema.columns
         where table_schema = 'public' and table_name = 'task_drive' and column_name in ('robo','robo_em')) as robo,
       (select relrowsecurity from pg_class where relname = 'pd_publicacao') as rls,
       (select data_type from information_schema.columns
         where table_schema = 'public' and table_name = 'approval_items' and column_name = 'publish_at') as tipo_data;
