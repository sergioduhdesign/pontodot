-- =====================================================================
-- SD Workflow — recorrência de tarefa + Central de Briefings (v4)
--
-- Roda por cima das migrações anteriores, sem problema (idempotente).
--
-- O que este arquivo faz:
--   1) tasks.recurrence — guarda a frequência da tarefa (dias da semana +
--      duração), pro botão "🔁 Tarefa recorrente" no modal de criar/editar.
--   2) Tabela client_briefings + bucket de storage "client-briefings" —
--      repositório de arquivos (doc/excel/pdf) por cliente, um por reunião
--      semanal. Só admin sobe; equipe e cliente (da própria empresa) só
--      veem/baixam.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Frequência da tarefa
-- ---------------------------------------------------------------------
alter table tasks add column if not exists recurrence jsonb;
comment on column tasks.recurrence is 'Ex: {"enabled":true,"days":["SEG","QUA"],"count":4,"unit":"semanas"}. Null = tarefa não recorrente.';

-- ---------------------------------------------------------------------
-- 2) Central de Briefings
-- ---------------------------------------------------------------------
create table if not exists client_briefings (
  id uuid primary key default gen_random_uuid(),
  client_id uuid not null references clients(id) on delete cascade,
  file_name text not null,
  file_url text not null,
  uploaded_by uuid references profiles(id),
  created_at timestamptz not null default now()
);

alter table client_briefings enable row level security;

drop policy if exists client_briefings_select_policy on client_briefings;
drop policy if exists client_briefings_insert_policy on client_briefings;
drop policy if exists client_briefings_delete_policy on client_briefings;

create policy client_briefings_select_policy on client_briefings for select
  using (
    my_role() in ('admin','team')
    or (my_role() = 'client' and client_id = my_client_id())
  );

-- Só admin sobe briefing (pedido explícito).
create policy client_briefings_insert_policy on client_briefings for insert
  with check (my_role() = 'admin');

create policy client_briefings_delete_policy on client_briefings for delete
  using (my_role() = 'admin');

-- Bucket de storage pros arquivos em si (doc/excel/pdf). Público pra leitura
-- (link direto abre/baixa o arquivo), só admin escreve.
insert into storage.buckets (id, name, public)
values ('client-briefings', 'client-briefings', true)
on conflict (id) do nothing;

drop policy if exists client_briefings_storage_read on storage.objects;
drop policy if exists client_briefings_storage_write on storage.objects;

create policy client_briefings_storage_read on storage.objects for select
  using (bucket_id = 'client-briefings');

create policy client_briefings_storage_write on storage.objects for insert
  with check (bucket_id = 'client-briefings' and my_role() = 'admin');

-- =====================================================================
-- Depois de rodar: no perfil de um cliente (Clientes → botão "Central de
-- Briefings", ou Minha Empresa pro próprio cliente), suba um PDF de teste
-- logado como admin. Confira que aparece a data e que abre ao clicar.
-- =====================================================================
