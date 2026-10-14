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
