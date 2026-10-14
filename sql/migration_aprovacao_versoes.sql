-- =====================================================================
-- SD Workflow — Aprovação: versões das peças + chave de origem
--
-- Rode UMA vez no SQL Editor do Supabase, ANTES de subir os arquivos
-- novos (novo-lote.html grava a coluna source_key).
--
-- 1) approval_items.source_key — o nome do arquivo/pasta de onde a peça
--    veio no pacote (o mesmo ARQUIVO_OU_PASTA do Excel). É ela que faz
--    o ZIP de correções achar a peça certa sozinho.
-- 2) approval_items.version — 1 na criação, +1 a cada correção enviada.
-- 3) approval_item_versions — foto de como a peça estava ANTES de cada
--    correção (artes, legenda, status). Nada se perde: dá pra ver a v1,
--    a v2… e o Excel de correções só traz o que o cliente pediu depois
--    da última versão.
-- 4) register_approval_version() — tira a foto e sobe a versão, no
--    servidor (security definer). Só equipe/admin.
--
-- Idempotente: pode rodar de novo sem quebrar nada.
-- =====================================================================

alter table approval_items add column if not exists source_key text;
alter table approval_items add column if not exists version int not null default 1;

create table if not exists approval_item_versions (
  id          uuid primary key default gen_random_uuid(),
  item_id     uuid not null references approval_items(id) on delete cascade,
  set_id      text,
  version     int  not null,
  media       jsonb,
  title       text,
  caption     text,
  hashtags    text,
  headline    text,
  publish_at  timestamptz,
  status      text,
  origem      text,            -- edicao | zip | troca
  created_by  uuid references profiles(id) on delete set null,
  created_at  timestamptz not null default now()
);
create index if not exists approval_item_versions_item_idx
  on approval_item_versions(item_id, version desc);

alter table approval_item_versions enable row level security;
drop policy if exists approval_item_versions_select on approval_item_versions;
create policy approval_item_versions_select on approval_item_versions for select
  using (exists (select 1 from profiles p where p.id = auth.uid() and p.role in ('admin','team')));
-- Sem policy de insert/update/delete: só a função abaixo grava.

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

  update approval_items set version = coalesce(version, 1) + 1
   where id = p_item_id
   returning version into v_nova;

  return v_nova;
end;
$$;

grant execute on function register_approval_version(uuid, text) to authenticated;
