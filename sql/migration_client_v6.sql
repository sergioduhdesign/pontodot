-- =====================================================================
-- SD Workflow — Base/Infra do "SD Workflow Design" (v6)
--
-- Primeira etapa do módulo de criação dentro da plataforma: o "kit de
-- marca" por cliente — paleta de cores, tipografia (upload de fontes) e
-- formatos/grids padrão (ex: Post 1080x1080, Story 1080x1920...).
--
-- Isso é a base sobre a qual os próximos módulos (redes sociais, campanha,
-- papelaria, books/apresentações) vão montar as peças — sem isso, nenhuma
-- peça sai no padrão certo da marca.
-- =====================================================================

create table if not exists brand_kits (
  id uuid primary key default gen_random_uuid(),
  client_id uuid not null unique references clients(id) on delete cascade,
  colors jsonb not null default '[]'::jsonb,   -- [{name, hex}]
  fonts jsonb not null default '[]'::jsonb,    -- [{name, file_url, file_name}]
  grids jsonb not null default '[]'::jsonb,    -- [{name, w, h}] — formatos padrão (px)
  notes text,
  updated_at timestamptz not null default now(),
  updated_by uuid references profiles(id)
);

alter table brand_kits enable row level security;

drop policy if exists brand_kits_select_policy on brand_kits;
drop policy if exists brand_kits_insert_policy on brand_kits;
drop policy if exists brand_kits_update_policy on brand_kits;
drop policy if exists brand_kits_delete_policy on brand_kits;

-- Ver: admin/equipe veem de todos os clientes; cliente só vê o próprio.
create policy brand_kits_select_policy on brand_kits for select
  using (
    my_role() in ('admin','team')
    or (my_role() = 'client' and client_id = my_client_id())
  );

-- Criar/editar/remover: só admin/equipe. Cliente nunca edita o próprio kit
-- de marca (evita cliente mudando cor/fonte por conta própria).
create policy brand_kits_insert_policy on brand_kits for insert
  with check (my_role() in ('admin','team'));

create policy brand_kits_update_policy on brand_kits for update
  using (my_role() in ('admin','team'));

create policy brand_kits_delete_policy on brand_kits for delete
  using (my_role() in ('admin','team'));

-- =====================================================================
-- Storage — arquivos de fonte (e futuramente outros ativos de marca:
-- logos em alta, imagens de referência de grid, etc.)
-- =====================================================================
insert into storage.buckets (id, name, public)
values ('brand-assets', 'brand-assets', true)
on conflict (id) do nothing;

drop policy if exists brand_assets_storage_read on storage.objects;
drop policy if exists brand_assets_storage_write on storage.objects;
drop policy if exists brand_assets_storage_delete on storage.objects;

create policy brand_assets_storage_read on storage.objects for select
  using (bucket_id = 'brand-assets');

create policy brand_assets_storage_write on storage.objects for insert
  with check (bucket_id = 'brand-assets' and my_role() in ('admin','team'));

create policy brand_assets_storage_delete on storage.objects for delete
  using (bucket_id = 'brand-assets' and my_role() in ('admin','team'));
