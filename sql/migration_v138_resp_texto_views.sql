-- v138 — Completa o v137: as colunas de responsável estavam PRESAS por 4 views e 1 policy.
-- Solta, converte pra texto, recria tudo igual e apaga o enum team_member de vez.
-- Idempotente: se já for texto, só recria views/policy.

begin;

drop view if exists public.v_bi_atrasadas;
drop view if exists public.v_bi_carga_equipe;
drop view if exists public.v_bi_paradas;
drop view if exists public.v_tracking_ativo;
drop policy if exists tasks_insert_policy on public.tasks;

do $$
declare c record;
begin
  for c in
    select n.nspname as esquema, cl.relname as tabela, a.attname as coluna, pg_get_expr(d.adbin, d.adrelid) as padrao
      from pg_attribute a
      join pg_class cl on cl.oid = a.attrelid and cl.relkind = 'r'
      join pg_namespace n on n.oid = cl.relnamespace
      join pg_type t on t.oid = a.atttypid
      left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
     where t.typname = 'team_member' and a.attnum > 0 and not a.attisdropped
  loop
    if c.padrao is not null then execute format('alter table %I.%I alter column %I drop default', c.esquema, c.tabela, c.coluna); end if;
    execute format('alter table %I.%I alter column %I type text using %I::text', c.esquema, c.tabela, c.coluna, c.coluna);
    if c.padrao is not null then execute format('alter table %I.%I alter column %I set default %s', c.esquema, c.tabela, c.coluna, regexp_replace(c.padrao, '::[a-z_.]*team_member', '::text', 'g')); end if;
    raise notice 'v138: %.% virou texto', c.tabela, c.coluna;
  end loop;
end $$;

-- as 4 views, exatamente como eram
create view public.v_bi_atrasadas as
  select t.id, t.action, t.client_id, c.name as cliente, t.project, t.resp, t.status, t.date_int, (current_date - t.date_int) as dias_atraso
    from tasks t left join clients c on c.id = t.client_id
   where t.status <> 'ENTREGUE'::task_status and t.date_int is not null and t.date_int < current_date
   order by (current_date - t.date_int) desc;

create view public.v_bi_carga_equipe as
  select resp as responsavel, count(*) as tarefas_abertas,
         count(*) filter (where status = 'ALTERAÇÃO'::task_status) as em_alteracao,
         count(*) filter (where status = 'DELEGADA'::task_status) as com_cliente,
         count(*) filter (where date_int is not null and date_int < current_date) as atrasadas
    from tasks where status <> 'ENTREGUE'::task_status
   group by resp order by count(*) desc;

create view public.v_bi_paradas as
  with ultima as (
    select distinct on (task_id) task_id, status_para, changed_at from task_status_history order by task_id, changed_at desc
  )
  select t.id, t.action, t.client_id, c.name as cliente, t.project, t.resp, t.status, u.changed_at as entrou_no_status_em,
         round((extract(epoch from (now() - u.changed_at)) / 3600.0), 1) as horas_parada
    from tasks t left join clients c on c.id = t.client_id left join ultima u on u.task_id = t.id
   where t.status <> 'ENTREGUE'::task_status
   order by round((extract(epoch from (now() - u.changed_at)) / 3600.0), 1) desc nulls last;

create view public.v_tracking_ativo as
  select s.id as session_id, s.user_id, p.name as user_name, p.avatar_url, p.team_key, s.task_id, t.action as task_action, t.status as task_status,
         c.name as client_name, c.color as client_color, s.started_at,
         (extract(epoch from (now() - s.started_at)))::integer as seconds_running, coalesce(t.total_seconds, 0) as seconds_total_before
    from task_sessions s join profiles p on p.id = s.user_id join tasks t on t.id = s.task_id left join clients c on c.id = t.client_id
   where s.ended_at is null;

grant all on public.v_bi_atrasadas, public.v_bi_carga_equipe, public.v_bi_paradas, public.v_tracking_ativo to anon, authenticated, service_role;

-- a policy de inserir tarefa: igual à antiga, mas "solicitação" vai pra quem tem a função ATENDIMENTO
-- (antes era o nome JOY fixo no banco; JOY continua valendo enquanto ninguém tiver a função)
create policy tasks_insert_policy on public.tasks for insert with check (
  (is_staff() and has_perm('tarefa.criar'::text))
  or (
    has_perm('tarefa.solicitar'::text)
    and status = 'BRIEFING'::task_status
    and (resp in (select team_key from public.profiles where active and 'atendimento' = any(funcoes)) or resp = 'JOY')
    and (is_staff() or (my_role() = 'client'::text and client_id = my_client_id()))
  )
);

-- o enum sai de cena (se algo ainda depender dele, avisa e segue)
do $$
begin
  begin
    execute 'drop type public.team_member';
    raise notice 'v138: enum team_member removido — responsável é texto livre';
  exception when others then
    raise notice 'v138: enum team_member mantido (%)', sqlerrm;
  end;
end $$;

commit;
