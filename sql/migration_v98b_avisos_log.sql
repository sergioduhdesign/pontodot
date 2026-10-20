-- Pontodot v98b — histórico de AVISOS EXTERNOS (WhatsApp + e-mail), só o admin lê
create table if not exists public.pd_avisos_log (
  id        bigserial primary key,
  ref       text,                         -- liga o WhatsApp ao e-mail espelho (mesma mensagem)
  canal     text not null check (canal in ('whatsapp','email')),
  para      text not null,                -- telefone ou e-mail
  quem      text,
  assunto   text,
  corpo     text,
  status    text not null default 'enviado',   -- enviado | erro | manual
  erro      text,
  origem    text,                         -- equipe | cliente | sistema
  client_id uuid,
  criado_em timestamptz not null default now()
);
create index if not exists pd_avisos_log_data_idx on public.pd_avisos_log (criado_em desc);
alter table public.pd_avisos_log enable row level security;
drop policy if exists pd_avisos_log_admin on public.pd_avisos_log;
create policy pd_avisos_log_admin on public.pd_avisos_log for select to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));
