-- Pontodot v179 — "Arquivos do site" (HostGator pelo cPanel): histórico de cada gravação, com o "antes" para desfazer.
-- Só o servidor escreve (service key); só admin lê. Seguro rodar mais de uma vez.
create table if not exists public.pd_site_arquivos_hist (
  id         uuid primary key default gen_random_uuid(),
  client_id  uuid not null references public.clients(id) on delete cascade,
  caminho    text not null,                 -- relativo à pasta do site
  existia    boolean not null default true, -- false = arquivo novo (não havia "antes")
  antes      text,
  depois     text,
  por        uuid,
  desfeito   boolean not null default false,
  criado_em  timestamptz not null default now()
);
create index if not exists pd_site_arquivos_hist_cli on public.pd_site_arquivos_hist (client_id, criado_em desc);
alter table public.pd_site_arquivos_hist enable row level security;
drop policy if exists pd_site_arq_ler on public.pd_site_arquivos_hist;
create policy pd_site_arq_ler on public.pd_site_arquivos_hist for select
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));
revoke all on public.pd_site_arquivos_hist from anon;
grant select on public.pd_site_arquivos_hist to authenticated;
-- desfazer: drop table public.pd_site_arquivos_hist;
