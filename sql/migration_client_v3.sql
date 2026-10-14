-- =====================================================================
-- SD Workflow — ajustes de acesso do cliente (v3)
--
-- Roda por cima da migration_client_v2.sql, sem problema (idempotente).
--
-- O que este arquivo faz:
--   1) Nova coluna tasks.client_created — marca (pra sempre, independente
--      do status atual) que a tarefa foi criada pelo cliente, não pela
--      equipe. Usada pra mostrar o selo azul "Veio do cliente" no
--      Kanban/Lista/Cronograma/Feed.
--   2) RLS de products: agora o cliente também pode CRIAR produto (só da
--      própria empresa) — o botão "+" ao lado do campo Produto, na tela de
--      criar tarefa, estava dando erro de permissão porque só admin/team
--      podiam inserir. Editar/apagar produto continua só com a equipe.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Selo "veio do cliente"
-- ---------------------------------------------------------------------
alter table tasks add column if not exists client_created boolean not null default false;
comment on column tasks.client_created is 'true = a tarefa foi criada pelo cliente (não pela equipe). Fica marcado pra sempre, mesmo depois que o status muda.';

-- ---------------------------------------------------------------------
-- 2) Cliente pode criar produto da própria empresa
-- ---------------------------------------------------------------------
drop policy if exists products_write_policy on products;
drop policy if exists products_insert_policy on products;
drop policy if exists products_update_policy on products;
drop policy if exists products_delete_policy on products;

create policy products_insert_policy on products for insert
  with check (
    my_role() in ('admin','team')
    or (my_role() = 'client' and client_id = my_client_id())
  );

create policy products_update_policy on products for update
  using (my_role() in ('admin','team'))
  with check (my_role() in ('admin','team'));

create policy products_delete_policy on products for delete
  using (my_role() in ('admin','team'));

-- =====================================================================
-- Depois de rodar: teste criando uma tarefa como cliente e adicionando um
-- produto novo ali na hora — não deve mais dar erro.
-- =====================================================================
