create table if not exists public.meta_campaign_products (
  campaign_id   text primary key,
  conta         text,
  campaign_name text,
  client_id     uuid references public.clients(id) on delete set null,
  product_id    uuid references public.products(id) on delete set null,
  atualizado_em timestamptz not null default now()
);
create index if not exists meta_campaign_products_nome_idx on public.meta_campaign_products (campaign_name);

alter table public.meta_campaign_products enable row level security;
drop policy if exists mcp_ler on public.meta_campaign_products;
drop policy if exists mcp_escrever on public.meta_campaign_products;
create policy mcp_ler on public.meta_campaign_products for select
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team'))
      or (client_id is not null and exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'client' and p.client_id = meta_campaign_products.client_id)));
create policy mcp_escrever on public.meta_campaign_products for all
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
grant select, insert, update, delete on public.meta_campaign_products to authenticated;

-- a campanha ganhou (ou trocou) o produto: os leads dela acompanham
create or replace function public.pd_campanha_produto_leads() returns trigger
language plpgsql security definer set search_path = public as $$
begin
  if pg_trigger_depth() > 1 then return new; end if;
  if new.campaign_name is not null then
    update public.meta_leads
       set product_id = new.product_id,
           client_id  = coalesce(client_id, new.client_id)
     where campanha_nome = new.campaign_name
       and product_id is distinct from new.product_id;
  end if;
  return new;
end $$;
drop trigger if exists pd_campanha_produto_leads_t on public.meta_campaign_products;
create trigger pd_campanha_produto_leads_t
  after insert or update of product_id, campaign_name on public.meta_campaign_products
  for each row execute function public.pd_campanha_produto_leads();

select to_regclass('public.meta_campaign_products') is not null as tabela_ok;
