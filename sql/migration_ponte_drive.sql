-- =====================================================================
-- SD Workflow — Ponte com o Google Drive (produção com IA)
--
-- Rode UMA vez no SQL Editor do Supabase. Idempotente.
--
-- 1) task_drive — a pasta do Drive de cada tarefa e o ciclo de produção:
--      aguardando → produzir → entregue → revisao → corrigir → entregue …
--      → liberado (cliente vê) → corrigir (cliente pediu) → …
--    Tabela separada de "tasks" de propósito: não mexe no import do
--    Excel de tarefas nem em nada que já funciona.
-- 2) approval_sets.interno — lote em REVISÃO INTERNA: a equipe vê, o
--    cliente não. "Liberar pro cliente" desliga a flag e gera o link.
--    Policies RESTRITIVAS: somam com as que já existem, sem substituí-las.
-- =====================================================================

create table if not exists task_drive (
  task_id          text primary key,
  folder_id        text not null,
  folder_url       text,
  entrada_id       text,
  saida_id         text,
  controle_id      text,
  controle_url     text,
  referencia_url   text,                 -- design system / referências da tarefa
  status           text not null default 'aguardando',
  rodada           int  not null default 0,
  revisao_interna  boolean not null default true,
  set_id           text,                 -- lote de aprovação ligado à tarefa
  arquivos_saida   int  not null default 0,
  ultimo_check     timestamptz,
  ultima_entrega   timestamptz,
  created_by       uuid references profiles(id) on delete set null,
  created_at       timestamptz not null default now(),
  updated_at       timestamptz not null default now()
);

alter table task_drive drop constraint if exists task_drive_status_check;
alter table task_drive add constraint task_drive_status_check
  check (status in ('aguardando','produzir','entregue','revisao','corrigir','liberado','pausado'));

create index if not exists task_drive_status_idx on task_drive(status);

-- Marco da rodada: o que a IA criou/alterou DEPOIS dele é entrega,
-- em qualquer pasta da tarefa (o usuário nunca precisa mover arquivo).
alter table task_drive add column if not exists marco timestamptz;

-- Briefing pra IA: tipo de entrega (press, feed, carrossel…) e
-- instruções extras do gestor. O briefing completo é montado pelo SD.
alter table task_drive add column if not exists tipo_entrega text;
alter table task_drive add column if not exists instrucoes_ia text;

alter table task_drive enable row level security;
drop policy if exists task_drive_equipe on task_drive;
create policy task_drive_equipe on task_drive for all
  using (exists (select 1 from profiles p where p.id = auth.uid() and p.role in ('admin','team')))
  with check (exists (select 1 from profiles p where p.id = auth.uid() and p.role in ('admin','team')));

-- ---------------------------------------------------------------------
-- Lote em revisão interna
-- ---------------------------------------------------------------------
alter table approval_sets add column if not exists interno boolean not null default false;

drop policy if exists approval_sets_interno_oculto on approval_sets;
create policy approval_sets_interno_oculto on approval_sets
  as restrictive for select to authenticated
  using (
    not interno
    or exists (select 1 from profiles p where p.id = auth.uid() and p.role in ('admin','team'))
  );

drop policy if exists approval_items_interno_oculto on approval_items;
create policy approval_items_interno_oculto on approval_items
  as restrictive for select to authenticated
  using (
    exists (select 1 from profiles p where p.id = auth.uid() and p.role in ('admin','team'))
    or not exists (select 1 from approval_sets s where s.id = approval_items.set_id and s.interno)
  );
