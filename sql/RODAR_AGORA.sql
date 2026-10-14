-- =================================================================
-- SD Workflow — RODAR AGORA (tudo idempotente: pode rodar de novo)
-- Junta: ponte do Drive (+ marco, tipo, instruções), aprovação
-- interna por peça e push em vários aparelhos.
-- =================================================================

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

-- =====================================================================
-- SD Workflow — Aprovação INTERNA por peça (gestor), separada da do cliente
--
-- approval_items.interno_ok — o gestor aprovou esta versão internamente.
-- Volta a false sozinho quando a peça ganha versão nova (correção).
-- set_internal_review() — marca/desmarca em lote. Só equipe/admin.
-- Idempotente.
-- =====================================================================

alter table approval_items add column if not exists interno_ok boolean not null default false;

create or replace function set_internal_review(p_item_ids uuid[], p_ok boolean)
returns int
language plpgsql
security definer
set search_path = public
as $$
declare v_role text; n int;
begin
  select role into v_role from profiles where id = auth.uid();
  if v_role is null or v_role not in ('admin','team') then
    raise exception 'Só a equipe aprova internamente.';
  end if;
  update approval_items set interno_ok = p_ok where id = any(p_item_ids);
  get diagnostics n = row_count;
  return n;
end;
$$;
grant execute on function set_internal_review(uuid[], boolean) to authenticated;

-- Versão nova (correção) = precisa de nova aprovação interna
create or replace function register_approval_version(p_item_id uuid, p_origem text default 'edicao')
returns int
language plpgsql
security definer
set search_path = public
as $$
declare
  v_role text;
  r      approval_items%rowtype;
  v_nova int;
begin
  select role into v_role from profiles where id = auth.uid();
  if v_role is null or v_role not in ('admin','team') then
    raise exception 'Só a equipe registra versões.';
  end if;

  select * into r from approval_items where id = p_item_id for update;
  if not found then raise exception 'Peça não encontrada.'; end if;

  insert into approval_item_versions
    (item_id, set_id, version, media, title, caption, hashtags, headline,
     publish_at, status, origem, created_by)
  values
    (r.id, r.set_id::text, coalesce(r.version, 1), r.media::jsonb, r.title, r.caption,
     r.hashtags, r.headline, r.publish_at::timestamptz, r.status::text, coalesce(p_origem,'edicao'), auth.uid());

  update approval_items set version = coalesce(version, 1) + 1, interno_ok = false
   where id = p_item_id
   returning version into v_nova;

  return v_nova;
end;
$$;
grant execute on function register_approval_version(uuid, text) to authenticated;

-- =====================================================================
-- SD Workflow — Push em VÁRIOS aparelhos por pessoa
--
-- Antes: a tabela aceitava UMA assinatura por usuário. Quando o celular
-- tentava se registrar depois do computador, o banco recusava (era o
-- erro "duplicate key … push_subscriptions" no log) — e o celular
-- nunca recebia push. Agora cada aparelho (endpoint) tem a sua linha.
-- Idempotente.
-- =====================================================================

-- 1) Tira a trava de "um por usuário" (constraint ou índice único)
do $$
declare c record;
begin
  for c in select conname from pg_constraint
           where conrelid = 'push_subscriptions'::regclass and contype = 'u' loop
    execute format('alter table push_subscriptions drop constraint %I', c.conname);
  end loop;
  for c in select indexname from pg_indexes
           where tablename = 'push_subscriptions' and indexdef ilike '%unique%' and indexdef ilike '%(user_id)%' loop
    execute format('drop index if exists %I', c.indexname);
  end loop;
end $$;

-- 2) Um aparelho = um endpoint
alter table push_subscriptions add column if not exists endpoint text;
update push_subscriptions
   set endpoint = coalesce(subscription::jsonb ->> 'endpoint', (subscription::jsonb #>> '{}')::jsonb ->> 'endpoint')
 where endpoint is null;
delete from push_subscriptions a using push_subscriptions b
 where a.endpoint = b.endpoint and a.ctid < b.ctid;
create unique index if not exists push_subscriptions_endpoint_key on push_subscriptions(endpoint);
create index if not exists push_subscriptions_user_idx on push_subscriptions(user_id);
