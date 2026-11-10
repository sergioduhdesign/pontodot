-- Pontodot v180 — pastas fechadas do "Arquivos do site": a PD não lista, lê nem grava nelas. Seguro rodar mais de uma vez.
alter table public.app_settings add column if not exists arquivos_fechados jsonb not null default '[]'::jsonb;
-- desfazer: alter table public.app_settings drop column arquivos_fechados;
