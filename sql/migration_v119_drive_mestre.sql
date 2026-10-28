-- v119 — Pasta mestre dos clientes no Drive configurável pelo painel (antes só por SD_DRIVE_CLIENTES no sd-config.php).
alter table public.app_settings add column if not exists drive_clientes_id text;
alter table public.app_settings add column if not exists drive_fontes_id text;
