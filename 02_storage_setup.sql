-- ================================================================
-- STUDIO DESIGN — Storage setup
-- Cole no SQL Editor do Supabase
-- ================================================================

-- Bucket para imagens de capa e logos
insert into storage.buckets (id, name, public)
values ('task-covers', 'task-covers', true)
on conflict (id) do nothing;

-- Política: qualquer autenticado pode fazer upload
create policy "Autenticados fazem upload"
  on storage.objects for insert
  with check (bucket_id = 'task-covers' AND auth.role() = 'authenticated');

-- Política: leitura pública (imagens de capa visíveis pro cliente)
create policy "Leitura pública das imagens"
  on storage.objects for select
  using (bucket_id = 'task-covers');

-- Política: dono pode deletar
create policy "Dono pode deletar"
  on storage.objects for delete
  using (bucket_id = 'task-covers' AND auth.uid()::text = owner);
