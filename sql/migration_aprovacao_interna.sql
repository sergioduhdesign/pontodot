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
