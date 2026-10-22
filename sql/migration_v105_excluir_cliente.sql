-- v105 · Excluir cliente com destino das tarefas e de tudo que é dele (inteligência, produtos, fichas…)
-- Núcleo sem checagem (só dono do banco); a função pública confere se quem chama é admin.

-- Conserta o gatilho de espelho (v101): escopo_id é texto e o id do cliente é uuid; faltava o cast e o DELETE quebrava.
create or replace function public.sd_espelho_cliente() returns trigger
language plpgsql security definer set search_path = public as $$
begin
  if tg_op = 'DELETE' then
    delete from public.sd_memoria     where escopo = 'cliente' and escopo_id::text = old.id::text;
    delete from public.sd_aprendizado where client_id = old.id;
    delete from public.client_knowledge where cliente_nome = old.name;
    update public.reunioes_texto set cliente = null, client_id = null, como_achou = 'nao_achou' where client_id = old.id;
    return old;
  end if;
  if tg_op = 'UPDATE' and old.active is distinct from new.active then
    if coalesce(new.active, true) = false then
      update public.sd_memoria     set ativo = false, suspenso_cliente = true where escopo = 'cliente' and escopo_id::text = new.id::text and ativo;
      update public.sd_aprendizado set ativo = false, suspenso_cliente = true where client_id = new.id and ativo;
    else
      update public.sd_memoria     set ativo = true, suspenso_cliente = false where escopo = 'cliente' and escopo_id::text = new.id::text and suspenso_cliente;
      update public.sd_aprendizado set ativo = true, suspenso_cliente = false where client_id = new.id and suspenso_cliente;
    end if;
  end if;
  return new;
end $$;

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

  -- memória da casa ligada ao cliente (escopo_id é texto): vai para o destino, senão o gatilho apaga
  if p_destino is not null then
    update sd_memoria set escopo_id = p_destino::text where escopo = 'cliente' and escopo_id::text = p_id::text;
  end if;

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
