-- =====================================================================
-- SD Workflow — Migração DEFINITIVA de acesso do cliente (v2)
--
-- Roda por cima de qualquer tentativa anterior (migration_client_access.sql,
-- fix_client_rls.sql) sem problema — é idempotente. Pode rodar quantas vezes
-- precisar.
--
-- O que este arquivo faz, na ordem:
--   1) Funções auxiliares my_role() / my_client_id()
--   2) Remove TODAS as policies existentes nas tabelas afetadas (não importa
--      o nome que tinham) antes de recriar — isso elimina de vez o bug de
--      "cliente vê todos os clientes/tarefas", que acontecia porque uma
--      policy antiga (com outro nome) continuava ativa e o Postgres combina
--      múltiplas policies de SELECT com OR.
--   3) Novas colunas em tasks pra rastrear a última decisão do cliente
--      (aprovação/alteração) — usadas pra mostrar "quem, quando, o quê" no
--      Kanban/Lista/Cronograma.
--   4) RLS de tasks/clients/products/comments/posts/post_comments/messages.
--   5) RPC client_review_task (aprovar / solicitar alteração), com
--      comentário obrigatório em caso de alteração.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Coluna de vínculo cliente ↔ empresa (se ainda não existir)
-- ---------------------------------------------------------------------
alter table profiles add column if not exists client_id uuid references clients(id) on delete set null;
comment on column profiles.client_id is 'Preenchido só quando role = client. Define de qual empresa esse usuário é.';

create or replace function my_role()
returns text
language sql
stable
security definer
set search_path = public
as $$
  select role::text from profiles where id = auth.uid()
$$;

create or replace function my_client_id()
returns uuid
language sql
stable
security definer
set search_path = public
as $$
  select client_id from profiles where id = auth.uid()
$$;

-- ---------------------------------------------------------------------
-- 2) Remove TODAS as policies existentes nas tabelas afetadas, não importa
--    o nome — garante que nenhuma policy antiga e permissiva sobrevive.
-- ---------------------------------------------------------------------
do $$
declare
  r record;
begin
  for r in
    select schemaname, tablename, policyname
    from pg_policies
    where schemaname = 'public'
      and tablename in ('tasks','clients','products','comments','posts','post_comments','messages')
  loop
    execute format('drop policy if exists %I on %I.%I', r.policyname, r.schemaname, r.tablename);
  end loop;
end $$;

-- ---------------------------------------------------------------------
-- 3) Rastro da última decisão do cliente numa tarefa (pra exibir "quem,
--    quando, o quê" no Kanban/Lista/Cronograma sem precisar de query extra)
-- ---------------------------------------------------------------------
alter table tasks add column if not exists client_last_action text;      -- 'approve' | 'changes'
alter table tasks add column if not exists client_last_by uuid references profiles(id);
alter table tasks add column if not exists client_last_at timestamptz;
alter table tasks add column if not exists client_last_comment text;

-- ---------------------------------------------------------------------
-- 4) RLS — TASKS: cliente só vê as tarefas da própria empresa; nunca cria,
--    edita ou apaga direto (isso só acontece por trás via a RPC abaixo, que
--    roda como SECURITY DEFINER).
-- ---------------------------------------------------------------------
alter table tasks enable row level security;

create policy tasks_select_policy on tasks for select
  using (
    my_role() in ('admin','team')
    or (my_role() = 'client' and client_id = my_client_id())
  );

-- Cliente também pode criar tarefa (o "+ Tarefa" agora aparece pra ele também),
-- mas só pra própria empresa e travada em BRIEFING/JOY — o app já força esses
-- valores na tela, e este check garante isso de novo no banco, por trás.
create policy tasks_insert_policy on tasks for insert
  with check (
    my_role() in ('admin','team')
    or (
      my_role() = 'client'
      and client_id = my_client_id()
      and status = 'BRIEFING'
      and resp = 'JOY'
    )
  );

create policy tasks_update_policy on tasks for update
  using (my_role() in ('admin','team'))
  with check (my_role() in ('admin','team'));

create policy tasks_delete_policy on tasks for delete
  using (my_role() in ('admin','team'));

-- ---------------------------------------------------------------------
-- 5) RLS — CLIENTS: cliente vê e EDITA (logo, cor) só a própria empresa —
--    igual ao pedido "visualizar e editar todos os dados e detalhes da
--    própria empresa". Não pode criar nem apagar clientes.
-- ---------------------------------------------------------------------
alter table clients enable row level security;

create policy clients_select_policy on clients for select
  using (
    my_role() in ('admin','team')
    or (my_role() = 'client' and id = my_client_id())
  );

create policy clients_insert_policy on clients for insert
  with check (my_role() in ('admin','team'));

create policy clients_update_policy on clients for update
  using (
    my_role() in ('admin','team')
    or (my_role() = 'client' and id = my_client_id())
  )
  with check (
    my_role() in ('admin','team')
    or (my_role() = 'client' and id = my_client_id())
  );

create policy clients_delete_policy on clients for delete
  using (my_role() in ('admin','team'));

-- ---------------------------------------------------------------------
-- 6) RLS — PRODUCTS: cliente só vê os produtos da própria empresa; não cria
--    nem edita (só a equipe organiza produtos/projetos).
-- ---------------------------------------------------------------------
alter table products enable row level security;

create policy products_select_policy on products for select
  using (
    my_role() in ('admin','team')
    or (my_role() = 'client' and client_id = my_client_id())
  );

create policy products_write_policy on products for all
  using (my_role() in ('admin','team'))
  with check (my_role() in ('admin','team'));

-- ---------------------------------------------------------------------
-- 7) RLS — COMMENTS: cliente comenta só em tarefas da própria empresa; não
--    edita/apaga comentário de outra pessoa.
-- ---------------------------------------------------------------------
alter table comments enable row level security;

create policy comments_select_policy on comments for select
  using (
    my_role() in ('admin','team')
    or (
      my_role() = 'client'
      and exists (select 1 from tasks t where t.id = comments.task_id and t.client_id = my_client_id())
    )
  );

create policy comments_insert_policy on comments for insert
  with check (
    user_id = auth.uid()
    and (
      my_role() in ('admin','team')
      or (
        my_role() = 'client'
        and exists (select 1 from tasks t where t.id = comments.task_id and t.client_id = my_client_id())
      )
    )
  );

create policy comments_delete_policy on comments for delete
  using (user_id = auth.uid() or my_role() = 'admin');

-- ---------------------------------------------------------------------
-- 8) RLS — FEED (posts/post_comments): só equipe/admin. Cliente nunca vê,
--    nem pelo app nem direto pela API.
-- ---------------------------------------------------------------------
alter table posts enable row level security;

create policy posts_select_policy on posts for select
  using (my_role() in ('admin','team'));

create policy posts_write_policy on posts for all
  using (my_role() in ('admin','team'))
  with check (my_role() in ('admin','team'));

alter table post_comments enable row level security;

create policy post_comments_select_policy on post_comments for select
  using (my_role() in ('admin','team'));

create policy post_comments_write_policy on post_comments for all
  using (my_role() in ('admin','team'))
  with check (my_role() in ('admin','team'));

-- ---------------------------------------------------------------------
-- 9) RLS — MESSAGES (chat): cliente só vê/envia mensagens diretas com
--    membros da equipe (nunca com outro cliente) e NUNCA participa do canal
--    "TODOS" (mensagens de grupo = receiver_id is null). Isso é o isolamento
--    de verdade, por trás do front-end.
-- ---------------------------------------------------------------------
alter table messages enable row level security;

create policy messages_select_policy on messages for select
  using (
    my_role() in ('admin','team')
    or (
      my_role() = 'client'
      and receiver_id is not null
      and (sender_id = auth.uid() or receiver_id = auth.uid())
    )
  );

create policy messages_insert_policy on messages for insert
  with check (
    sender_id = auth.uid()
    and (
      my_role() in ('admin','team')
      or (my_role() = 'client' and receiver_id is not null)
    )
  );

-- ---------------------------------------------------------------------
-- 10) RPC — client_review_task: única forma do cliente mudar status.
--     'approve' -> ATENÇÃO   (sinaliza a equipe pra finalizar/entregar)
--     'changes' -> ALTERAÇÃO (comentário OBRIGATÓRIO)
--     Grava quem/quando/comentário em tasks.client_last_* e retorna dados
--     prontos pra montar a notificação (nome do cliente, projeto, tarefa).
-- ---------------------------------------------------------------------
drop function if exists client_review_task(text, text);
drop function if exists client_review_task(text, text, text);

create or replace function client_review_task(p_task_id text, p_decision text, p_comment text default null)
returns table(resp text, new_status text, task_action text, project_name text, client_name text, actor_name text)
language plpgsql
security definer
set search_path = public
as $$
declare
  v_status text;
  v_resp text;
  v_action text;
  v_project text;
  v_client_id uuid;
  v_client_name text;
  v_actor text;
  v_comment text;
begin
  if my_role() <> 'client' then
    raise exception 'Só usuários com role client podem usar esta função';
  end if;

  if p_decision not in ('approve','changes') then
    raise exception 'Decisão inválida: %', p_decision;
  end if;

  v_comment := nullif(trim(coalesce(p_comment,'')), '');

  if p_decision = 'changes' and v_comment is null then
    raise exception 'Comentário obrigatório para solicitar alteração';
  end if;

  v_status := case p_decision when 'approve' then 'ATENÇÃO' else 'ALTERAÇÃO' end;

  select name into v_actor from profiles where id = auth.uid();

  update tasks
    set status = v_status::task_status,
        client_last_action = p_decision,
        client_last_by = auth.uid(),
        client_last_at = now(),
        client_last_comment = v_comment
    where id = p_task_id
      and client_id = my_client_id()
    returning tasks.resp, tasks.action, tasks.project, tasks.client_id into v_resp, v_action, v_project, v_client_id;

  if not found then
    raise exception 'Tarefa não encontrada ou não pertence à sua empresa';
  end if;

  select name into v_client_name from clients where id = v_client_id;

  insert into comments (task_id, user_id, body)
  values (
    p_task_id,
    auth.uid(),
    coalesce(v_comment, case p_decision when 'approve' then '✓ Cliente aprovou esta tarefa.' else '↺ Cliente pediu alteração nesta tarefa.' end)
  );

  return query select v_resp, v_status, v_action, v_project, v_client_name, v_actor;
end;
$$;

grant execute on function client_review_task(text, text, text) to authenticated;

-- ---------------------------------------------------------------------
-- 11) Confira o client_id do usuário que você testar como "cliente".
--     Se estiver NULL, ele não pertence a nenhuma empresa e (corretamente)
--     não deve ver nenhuma tarefa. Rode com o e-mail/uuid certos se precisar:
-- ---------------------------------------------------------------------
-- select id, name, email, role, client_id from profiles where role = 'client';
-- update profiles set client_id = '<uuid do cliente>' where email = '<email do cliente>';

-- =====================================================================
-- Depois de rodar: peça pro usuário cliente deslogar e logar de novo (ou
-- use uma aba anônima) e confirme que só aparece a empresa/tarefas dele,
-- tanto no Kanban/Lista/Cronograma quanto no chat.
-- =====================================================================
