-- v131 · IA do atendimento no zap (cliente) — liga por cliente
alter table public.clients add column if not exists ia_zap boolean not null default false;
-- quem recebe pedidos e chamados dos clientes (team_key)
alter table public.app_settings add column if not exists atendimento_resp text default 'JOY';
