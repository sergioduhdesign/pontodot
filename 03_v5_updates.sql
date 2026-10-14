-- v5: coluna active para clientes (encerrar/reativar)
alter table clients add column if not exists active boolean default true;

-- v5: tabela post_comments (comentários nos posts do feed)
create table if not exists post_comments (
  id          uuid primary key default uuid_generate_v4(),
  post_id     uuid references posts(id) on delete cascade,
  user_id     uuid references profiles(id),
  body        text not null,
  created_at  timestamptz default now()
);
alter table post_comments enable row level security;

create policy if not exists "Equipe vê post_comments"
  on post_comments for select
  using (exists (select 1 from profiles where id = auth.uid()));

create policy if not exists "Autenticado comenta post"
  on post_comments for insert
  with check (user_id = auth.uid());
