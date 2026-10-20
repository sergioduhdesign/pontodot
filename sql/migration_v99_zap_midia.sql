-- v99 — Zap robusto: mídia (imagem, áudio, vídeo, documento), apagar e controles por conversa.
-- Rode INTEIRO no Supabase → SQL Editor → Run. Esperado: "Success".
-- Não apaga nada existente.

-- 1) o histórico passa a guardar o arquivo e o "apagado"
alter table public.pd_avisos_log
  add column if not exists midia_path text,        -- caminho no bucket zap-midia
  add column if not exists midia_tipo text,        -- image | audio | video | document | sticker
  add column if not exists midia_nome text,        -- nome do arquivo (documento)
  add column if not exists apagado_em timestamptz; -- apagado na plataforma (o WhatsApp da pessoa não muda)

-- 2) controles por conversa (a chave é o telefone só com números, com 55)
create table if not exists public.pd_zap_conv (
  chave        text primary key,
  arquivada    boolean not null default false,
  fixada       boolean not null default false,
  nota         text,
  atualizado_em timestamptz not null default now()
);
alter table public.pd_zap_conv enable row level security;
drop policy if exists pd_zap_conv_admin on public.pd_zap_conv;
create policy pd_zap_conv_admin on public.pd_zap_conv for all to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));

-- admin também pode marcar mensagem como apagada
drop policy if exists pd_avisos_log_admin_upd on public.pd_avisos_log;
create policy pd_avisos_log_admin_upd on public.pd_avisos_log for update to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));

-- 3) o espaço dos arquivos (privado: só admin lê/sobe; o servidor usa a chave dele)
insert into storage.buckets (id, name, public) values ('zap-midia', 'zap-midia', false)
  on conflict (id) do nothing;
drop policy if exists zap_midia_admin_ler on storage.objects;
create policy zap_midia_admin_ler on storage.objects for select to authenticated
  using (bucket_id = 'zap-midia' and exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));
drop policy if exists zap_midia_admin_subir on storage.objects;
create policy zap_midia_admin_subir on storage.objects for insert to authenticated
  with check (bucket_id = 'zap-midia' and exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));
