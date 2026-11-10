-- Pontodot v181 — acesso universal da casa (HostGator): credencial SEM cliente, guardada uma vez.
-- Seguro rodar mais de uma vez.
alter table public.pd_site_arquivos_hist alter column client_id drop not null;
create unique index if not exists pd_cred_casa_uq on public.pd_credenciais (servico) where client_id is null;
