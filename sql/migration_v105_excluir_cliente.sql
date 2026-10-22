-- v105 · Excluir cliente com destino das tarefas e de tudo que é dele (inteligência, produtos, fichas…)
-- Núcleo sem checagem (só dono do banco); a função pública confere se quem chama é admin.

create or replace function public._excluir_cliente_core(p_id uuid, p_destino uuid default null)
returns jsonb language plpgsql security definer set search_path = public as $$
declare r record; n int; rel jsonb := '{}'::jsonb; nome text;
begin
  select name into nome from clients where id = p_id;
  if nome is null then raise exception 'cliente não existe'; end if;
  if p_destino is not null and (p_destino = p_id or not exists (select 1 from clients where id = p_destino)) then
    raise exception 'destino inválido';
  end if;

  -- toda tabela do schema public com coluna client_id (tarefas primeiro), menos clients e profiles
  for r in
    select c.table_name from information_schema.columns c
      join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name and t.table_type = 'BASE TABLE'
     where c.table_schema = 'public' and c.column_name = 'client_id' and c.table_name not in ('clients', 'profiles')
     order by (c.table_name = 'tasks') desc, c.table_name
  loop
    if p_destino is not null then
      begin
        execute format('update public.%I set client_id = $1 where client_id = $2', r.table_name) using p_destino, p_id;
        get diagnostics n = row_count; rel := rel || jsonb_build_object(r.table_name || '_movidas', n);
      exception when unique_violation then
        -- já existe o equivalente no destino: o repetido do cliente que sai é descartado
        execute format('delete from public.%I where client_id = $1', r.table_name) using p_id;
        get diagnostics n = row_count; rel := rel || jsonb_build_object(r.table_name || '_repetidas_descartadas', n);
      end;
    else
      execute format('delete from public.%I where client_id = $1', r.table_name) using p_id;
      get diagnostics n = row_count; rel := rel || jsonb_build_object(r.table_name || '_apagadas', n);
    end if;
  end loop;

  -- usuários que representavam o cliente (role client)
  if p_destino is not null then update profiles set client_id = p_destino where client_id = p_id;
  else update profiles set active = false where client_id = p_id; end if;

  -- ficha completa guardada por nome
  delete from client_docs where lower(cliente_nome) = lower(nome);

  delete from clients where id = p_id;
  return jsonb_build_object('cliente', nome, 'destino', p_destino, 'detalhe', rel);
end $$;

revoke all on function public._excluir_cliente_core(uuid, uuid) from public, anon, authenticated;

create or replace function public.excluir_cliente(p_id uuid, p_destino uuid default null)
returns jsonb language plpgsql security definer set search_path = public as $$
begin
  if not exists (select 1 from profiles where id = auth.uid() and role = 'admin') then
    raise exception 'só administrador exclui cliente';
  end if;
  return public._excluir_cliente_core(p_id, p_destino);
end $$;

revoke all on function public.excluir_cliente(uuid, uuid) from public, anon;
grant execute on function public.excluir_cliente(uuid, uuid) to authenticated;

-- Duplicados criados por duplo clique: mantém o mais antigo, passa tudo para ele e remove o repetido.
do $$
declare d record;
begin
  for d in
    select c.id as dup, k.keep
      from clients c
      join (select lower(trim(name)) as nm, (array_agg(id order by id))[1] as keep
              from clients group by 1 having count(*) > 1) k on lower(trim(c.name)) = k.nm
     where c.id <> k.keep
  loop
    perform public._excluir_cliente_core(d.dup, d.keep);
  end loop;
end $$;
