-- =====================================================================
-- SD Workflow — Presença, Notificações e Curtidas de tarefa (v10)
--
-- 1) profiles.presence_status/last_active — status pessoal (online/ocupado/
--    ausente/reunião/offline), escolhido em "Meu Perfil", exibido como
--    bolinha colorida no avatar (header e lista de chat).
-- 2) notifications — toda "coisa nova" que acontece pra alguém (tarefa
--    criada, aprovada, alterada, comentário etc.) vira uma linha aqui.
--    O app assina em tempo real (postgres_changes insert filtrado por
--    user_id) pra mostrar o balão flutuante e, junto, disparar o push.
-- 3) task_likes — "curtir" numa tarefa (mesmo padrão do post_likes já
--    usado no Feed). task_id fica como texto (sem FK) porque o ID de
--    tarefa já é gerado por next_task_id() e não precisamos travar o tipo
--    aqui — evita quebrar se o tipo mudar.
-- =====================================================================

alter table profiles add column if not exists presence_status text not null default 'online';
alter table profiles add column if not exists last_active timestamptz not null default now();
alter table profiles drop constraint if exists profiles_presence_status_check;
alter table profiles add constraint profiles_presence_status_check
  check (presence_status in ('online','ocupado','ausente','reuniao','offline'));

create table if not exists notifications (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references profiles(id) on delete cascade, -- quem recebe
  actor_id uuid references profiles(id) on delete set null,        -- quem causou
  type text not null,        -- task_new | task_status | task_approved | task_change_request | comment | mention
  title text not null,
  body text,
  link text,                 -- ex: 'task:123' — o app resolve e abre o modal certo
  read boolean not null default false,
  created_at timestamptz not null default now()
);
create index if not exists notifications_user_idx on notifications(user_id, read, created_at desc);

alter table notifications enable row level security;
drop policy if exists notifications_select_policy on notifications;
drop policy if exists notifications_insert_policy on notifications;
drop policy if exists notifications_update_policy on notifications;

-- Ver: só as próprias notificações.
create policy notifications_select_policy on notifications for select
  using (user_id = auth.uid());
-- Criar: qualquer usuário autenticado pode gerar uma notificação pra outro
-- (é o próprio app que dispara ao criar/aprovar/alterar uma tarefa) — mesmo
-- modelo de confiança já usado em comments/messages neste app.
create policy notifications_insert_policy on notifications for insert
  with check (auth.role() = 'authenticated');
-- Marcar como lida / descartar: só o próprio dono.
create policy notifications_update_policy on notifications for update
  using (user_id = auth.uid());

create table if not exists task_likes (
  id uuid primary key default gen_random_uuid(),
  task_id text not null,
  user_id uuid not null references profiles(id) on delete cascade,
  created_at timestamptz not null default now(),
  unique(task_id, user_id)
);
create index if not exists task_likes_task_idx on task_likes(task_id);

alter table task_likes enable row level security;
drop policy if exists task_likes_select_policy on task_likes;
drop policy if exists task_likes_insert_policy on task_likes;
drop policy if exists task_likes_delete_policy on task_likes;

create policy task_likes_select_policy on task_likes for select
  using (auth.role() = 'authenticated');
create policy task_likes_insert_policy on task_likes for insert
  with check (auth.uid() = user_id);
create policy task_likes_delete_policy on task_likes for delete
  using (auth.uid() = user_id);
