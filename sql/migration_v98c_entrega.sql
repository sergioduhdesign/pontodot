-- Pontodot v98c — status de ENTREGA do WhatsApp (sent/delivered/read/failed)
alter table public.pd_avisos_log add column if not exists wamid text;
alter table public.pd_avisos_log add column if not exists entrega text;
create index if not exists pd_avisos_log_wamid_idx on public.pd_avisos_log (wamid);
