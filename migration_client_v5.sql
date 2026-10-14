-- =====================================================================
-- SD Workflow — banco de imagens da splash (v5)
--
-- Cria o bucket "splash-images" no Supabase Storage — é o "banco de
-- imagens" que alimenta a tela de abertura (uma imagem nova sorteada a
-- cada refresh/entrada no app.html) e o fundo da gaveta de Histórico.
--
-- Todo mundo logado pode VER (listar/baixar) as imagens; só admin pode
-- subir/remover — pra manter o banco curado.
-- =====================================================================

insert into storage.buckets (id, name, public)
values ('splash-images', 'splash-images', true)
on conflict (id) do nothing;

drop policy if exists splash_images_storage_read on storage.objects;
drop policy if exists splash_images_storage_write on storage.objects;
drop policy if exists splash_images_storage_delete on storage.objects;

create policy splash_images_storage_read on storage.objects for select
  using (bucket_id = 'splash-images');

create policy splash_images_storage_write on storage.objects for insert
  with check (bucket_id = 'splash-images' and my_role() = 'admin');

create policy splash_images_storage_delete on storage.objects for delete
  using (bucket_id = 'splash-images' and my_role() = 'admin');

-- =====================================================================
-- Depois de rodar: no painel do Supabase (Storage → splash-images), suba
-- as fotos do banco direto por lá (mais rápido que fazer upload um a um
-- pela plataforma). Pode subir quantas quiser — a cada refresh o app
-- sorteia uma aleatória.
-- =====================================================================
