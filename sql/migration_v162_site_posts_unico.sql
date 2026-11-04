-- =====================================================================
-- v162 — peças de SITE (blog / capa_site) na esteira de aprovação
--
-- 1) pd_site_posts: item_id já é PRIMARY KEY (v62) — é a chave natural
--    que o w_sites (pd-worker.php) e o pd-sites.php usam no upsert
--    (?on_conflict=item_id + Prefer: resolution=merge-duplicates).
--    Garante a unicidade caso a tabela tenha sido criada sem a PK.
-- 2) approval_items.kind: se existir CHECK limitando os valores
--    (feed/carrossel/stories/reels/anuncio/material…), recria aceitando
--    também 'blog' e 'capa_site'. Sem CHECK, nada muda.
-- Idempotente: pode rodar mais de uma vez.
-- =====================================================================

-- 1) chave natural do que já foi pro site
do $$
begin
  if not exists (
    select 1 from pg_constraint c
     join pg_class t on t.oid = c.conrelid
     where t.relname = 'pd_site_posts' and c.contype in ('p','u')
       and c.conkey = array[(select attnum from pg_attribute where attrelid = t.oid and attname = 'item_id')]
  ) then
    alter table public.pd_site_posts add constraint pd_site_posts_item_id_unico unique (item_id);
  end if;
end $$;

-- 2) kind de peça: libera blog e capa_site
do $$
declare
  r record;
  vals text;
begin
  for r in
    select c.conname, pg_get_constraintdef(c.oid) as def
      from pg_constraint c
      join pg_class t on t.oid = c.conrelid
      join pg_namespace n on n.oid = t.relnamespace
     where n.nspname = 'public' and t.relname = 'approval_items' and c.contype = 'c'
       and pg_get_constraintdef(c.oid) ilike '%kind%'
  loop
    if r.def ilike '%blog%' and r.def ilike '%capa_site%' then continue; end if;   -- já liberado
    -- mantém a regra original e só soma os dois valores de site
    vals := regexp_replace(r.def, '^\s*CHECK\s*', '', 'i');
    execute format('alter table public.approval_items drop constraint %I', r.conname);
    execute format('alter table public.approval_items add constraint %I check ((%s) or kind in (''blog'',''capa_site''))', r.conname, vals);
  end loop;
end $$;
