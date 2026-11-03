-- v149 · certificado confirmado pelo admin (impressão digital) por servidor de e-mail
alter table public.pd_vendas_caixas add column if not exists tls_pin_imap text;
alter table public.pd_vendas_caixas add column if not exists tls_pin_smtp text;
select 'v149 ok' as resultado;
