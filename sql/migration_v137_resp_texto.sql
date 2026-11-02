-- v137 — DEFINITIVO: responsável deixa de ser lista fechada.
-- tasks.resp e profiles.team_key (e qualquer outra coluna do tipo team_member) viram TEXTO.
-- Pessoa nova = perfil novo, e pronto. Nada de alimentar enum, nem por função.
-- Pode rodar mais de uma vez. Se alguma coluna estiver presa por uma view, a migração
-- avisa (NOTICE) e deixa aquela coluna como está — o v136 continua cobrindo o enum nesse caso.

do $$
declare c record; ok int := 0; presas int := 0;
begin
  for c in
    select n.nspname as esquema, cl.relname as tabela, a.attname as coluna,
           pg_get_expr(d.adbin, d.adrelid) as padrao
      from pg_attribute a
      join pg_class cl on cl.oid = a.attrelid and cl.relkind = 'r'
      join pg_namespace n on n.oid = cl.relnamespace
      join pg_type t on t.oid = a.atttypid
      left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
     where t.typname = 'team_member' and a.attnum > 0 and not a.attisdropped
  loop
    begin
      if c.padrao is not null then
        execute format('alter table %I.%I alter column %I drop default', c.esquema, c.tabela, c.coluna);
      end if;
      execute format('alter table %I.%I alter column %I type text using %I::text', c.esquema, c.tabela, c.coluna, c.coluna);
      if c.padrao is not null then
        execute format('alter table %I.%I alter column %I set default %s', c.esquema, c.tabela, c.coluna, regexp_replace(c.padrao, '::[a-z_.]*team_member', '::text', 'g'));
      end if;
      ok := ok + 1;
      raise notice 'v137: %.% virou texto', c.tabela, c.coluna;
    exception when others then
      presas := presas + 1;
      raise notice 'v137: %.% NÃO converteu (%). O v136 segue cobrindo essa coluna.', c.tabela, c.coluna, sqlerrm;
    end;
  end loop;
  raise notice 'v137: % coluna(s) convertida(s), % presa(s)', ok, presas;
end $$;

-- o tipo só sai se ninguém mais usar (se ainda houver coluna presa ou função com esse tipo, fica)
do $$
begin
  begin
    execute 'drop type public.team_member';
    raise notice 'v137: enum team_member removido — responsável é texto livre';
  exception when others then
    raise notice 'v137: enum team_member mantido (%)', sqlerrm;
  end;
end $$;

-- a função do v136 passa a ser inofensiva quando o enum não existe mais
create or replace function public.pd_team_member_garante(k text)
returns boolean
language plpgsql
security definer
set search_path = public
as $$
declare
  papel text;
begin
  k := upper(trim(k));
  if k !~ '^[A-Z][A-Z0-9]{1,19}$' then return false; end if;
  if not exists (select 1 from pg_type where typname = 'team_member') then return true; end if;
  if auth.role() is distinct from 'service_role' then
    select role into papel from public.profiles where id = auth.uid();
    if papel is distinct from 'admin' then raise exception 'só admin'; end if;
  end if;
  if not exists (
    select 1 from pg_enum e join pg_type t on t.oid = e.enumtypid
    where t.typname = 'team_member' and e.enumlabel = k
  ) then
    execute format('alter type public.team_member add value %L', k);
  end if;
  return true;
end
$$;
