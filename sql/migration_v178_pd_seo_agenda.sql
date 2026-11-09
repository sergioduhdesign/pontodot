-- Pontodot v178 — agenda de auditorias de SEO (o relógio da casa roda sozinho, de x em x dias). Seguro rodar mais de uma vez.
alter table public.app_settings add column if not exists seo_agenda jsonb not null default '{"ativo":false,"dias":7}'::jsonb;
-- desfazer: alter table public.app_settings drop column seo_agenda;
