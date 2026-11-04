-- =====================================================================
-- v155 · PRODUTO nas contas sociais e nos leads
-- Rode UMA vez: Supabase > SQL Editor > New query > cola > Run. Pode rodar de novo.
--
-- Por quê: uma Página/Instagram nem sempre é de um CLIENTE — muitas vezes é de um
-- PRODUTO (empreendimento) que mora embaixo do cliente (ex.: "Nova Oliveira").
--   social_accounts.product_id  a conta pertence a este produto (vazio = a empresa toda)
--   meta_leads.product_id       o lead nasce já com o produto da Página que o recebeu
-- Quando você troca o produto (ou o cliente) da Página, os leads dela se acertam sozinhos.
-- =====================================================================

alter table public.social_accounts add column if not exists product_id uuid references public.products(id) on delete set null;
alter table public.meta_leads      add column if not exists product_id uuid references public.products(id) on delete set null;
create index if not exists social_accounts_product_idx on public.social_accounts (product_id);
create index if not exists meta_leads_product_idx      on public.meta_leads (product_id);

-- a equipe mexe no produto do lead e da conta pelo app
grant update (product_id) on public.social_accounts to authenticated;
grant update (status, client_id, product_id) on public.meta_leads to authenticated;

-- Página (Facebook) mudou de cliente/produto → os leads dela acompanham
create or replace function public.pd_conta_social_leads() returns trigger
language plpgsql security definer set search_path = public as $$
begin
  if pg_trigger_depth() > 1 then return new; end if;
  if new.platform = 'facebook' and new.external_account_id is not null then
    update public.meta_leads
       set client_id = new.client_id, product_id = new.product_id
     where page_id = new.external_account_id
       and (client_id is distinct from new.client_id or product_id is distinct from new.product_id);
  end if;
  return new;
end $$;

drop trigger if exists pd_conta_social_leads_t on public.social_accounts;
create trigger pd_conta_social_leads_t
  after insert or update of client_id, product_id on public.social_accounts
  for each row execute function public.pd_conta_social_leads();

-- produto sempre dentro do cliente certo: se trocar o cliente da conta, o produto antigo sai
create or replace function public.pd_conta_social_produto_ok() returns trigger
language plpgsql security definer set search_path = public as $$
begin
  if new.product_id is not null and not exists
     (select 1 from public.products p where p.id = new.product_id and p.client_id is not distinct from new.client_id) then
    new.product_id := null;
  end if;
  return new;
end $$;

drop trigger if exists pd_conta_social_produto_ok_t on public.social_accounts;
create trigger pd_conta_social_produto_ok_t
  before insert or update of client_id, product_id on public.social_accounts
  for each row execute function public.pd_conta_social_produto_ok();

-- conferência: deve devolver  t | t
select
  exists (select 1 from information_schema.columns where table_name='social_accounts' and column_name='product_id') as conta_tem_produto,
  exists (select 1 from information_schema.columns where table_name='meta_leads'      and column_name='product_id') as lead_tem_produto;
