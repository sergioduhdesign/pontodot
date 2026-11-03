-- v141 — Excluir usuário DE VERDADE (painel, chat e zap).
-- O Auth devolvia 500 porque tabelas ainda apontavam pro usuário (tarefas criadas, comentários, sessões, memória…).
-- Aqui TODA chave estrangeira que aponta pra auth.users(id) ou public.profiles(id) passa a:
--   • coluna opcional  → ON DELETE SET NULL (a tarefa/comentário fica, só perde o autor)
--   • coluna obrigatória → ON DELETE CASCADE (o registro é da pessoa: sessão, permissão, alarme…)
-- Pode rodar mais de uma vez.

do $$
declare c record; acao text;
begin
  for c in
    select con.conname, n.nspname as esquema, cl.relname as tabela,
           a.attname as coluna, a.attnotnull,
           rn.nspname as ref_esquema, rcl.relname as ref_tabela,
           pg_get_constraintdef(con.oid) as def
      from pg_constraint con
      join pg_class cl on cl.oid = con.conrelid
      join pg_namespace n on n.oid = cl.relnamespace
      join pg_class rcl on rcl.oid = con.confrelid
      join pg_namespace rn on rn.oid = rcl.relnamespace
      join pg_attribute a on a.attrelid = con.conrelid and a.attnum = con.conkey[1]
     where con.contype = 'f'
       and array_length(con.conkey, 1) = 1
       and ((rn.nspname = 'auth' and rcl.relname = 'users') or (rn.nspname = 'public' and rcl.relname = 'profiles'))
       and not (n.nspname = 'public' and cl.relname = 'profiles' and rn.nspname = 'auth')   -- profiles → auth.users: cascade sempre
  loop
    acao := case when c.attnotnull then 'cascade' else 'set null' end;
    if c.def ilike '%on delete ' || acao || '%' then continue; end if;
    execute format('alter table %I.%I drop constraint %I', c.esquema, c.tabela, c.conname);
    execute format('alter table %I.%I add constraint %I foreign key (%I) references %I.%I(id) on delete %s',
                   c.esquema, c.tabela, c.conname, c.coluna, c.ref_esquema, c.ref_tabela, acao);
    raise notice 'v141: %.%.% → on delete %', c.esquema, c.tabela, c.coluna, acao;
  end loop;

  -- profiles → auth.users: apagar o usuário apaga o perfil (e o perfil apagado leva o resto pelas regras acima)
  for c in
    select con.conname from pg_constraint con
      join pg_class cl on cl.oid = con.conrelid join pg_namespace n on n.oid = cl.relnamespace
      join pg_class rcl on rcl.oid = con.confrelid join pg_namespace rn on rn.oid = rcl.relnamespace
     where con.contype = 'f' and n.nspname = 'public' and cl.relname = 'profiles' and rn.nspname = 'auth' and rcl.relname = 'users'
       and pg_get_constraintdef(con.oid) not ilike '%on delete cascade%'
  loop
    execute format('alter table public.profiles drop constraint %I', c.conname);
    execute format('alter table public.profiles add constraint %I foreign key (id) references auth.users(id) on delete cascade', c.conname);
    raise notice 'v141: profiles.id → auth.users on delete cascade';
  end loop;
end $$;

-- conferência: tudo que ainda aponta pra usuário/perfil e como se comporta ao apagar
select n.nspname || '.' || cl.relname as tabela, a.attname as coluna,
       rn.nspname || '.' || rcl.relname as aponta_para,
       case con.confdeltype when 'c' then 'cascade' when 'n' then 'set null' when 'a' then 'NO ACTION (bloqueia!)' when 'r' then 'RESTRICT (bloqueia!)' else con.confdeltype::text end as ao_apagar
  from pg_constraint con
  join pg_class cl on cl.oid = con.conrelid join pg_namespace n on n.oid = cl.relnamespace
  join pg_class rcl on rcl.oid = con.confrelid join pg_namespace rn on rn.oid = rcl.relnamespace
  join pg_attribute a on a.attrelid = con.conrelid and a.attnum = con.conkey[1]
 where con.contype = 'f' and ((rn.nspname = 'auth' and rcl.relname = 'users') or (rn.nspname = 'public' and rcl.relname = 'profiles'))
 order by 1, 2;
