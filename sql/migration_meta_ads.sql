-- =====================================================================
-- Pontodot — ANÚNCIOS E LEADS DA META
-- Rode UMA vez: Supabase > SQL Editor > New query > cola > Run.
-- Pode rodar de novo sem medo: tudo aqui é "se ainda não existe".
--
--   meta_ad_accounts  as contas de anúncio que a Meta liberou no login
--                     (da SD e dos clientes que deram acesso de parceiro)
--   meta_user_tokens  a chave de quem conectou — SÓ o servidor lê.
--                     Nem admin vê pelo app: não tem política nenhuma.
--   meta_leads        cada pessoa que preencheu um formulário de anúncio
-- =====================================================================

create table if not exists public.meta_ad_accounts (
  id             text primary key,                 -- "act_123456789"
  nome           text,
  moeda          text,
  empresa        text,                             -- o Business dono da conta
  status_meta    int,                              -- 1 = ativa (código da Meta)
  client_id      uuid references public.clients(id) on delete set null,
  conectada_por  uuid references auth.users(id) on delete set null,
  atualizado_em  timestamptz not null default now()
);

create table if not exists public.meta_user_tokens (
  user_id        uuid primary key references auth.users(id) on delete cascade,
  access_token   text not null,
  expira_em      timestamptz,
  atualizado_em  timestamptz not null default now()
);

create table if not exists public.meta_leads (
  id             text primary key,                 -- leadgen_id da Meta (não duplica)
  page_id        text,
  form_id        text,
  ad_id          text,
  ad_nome        text,
  campanha_nome  text,
  nome           text,
  email          text,
  telefone       text,
  campos         jsonb not null default '{}'::jsonb, -- todas as respostas do formulário
  client_id      uuid references public.clients(id) on delete set null,
  criado_na_meta timestamptz,
  recebido_em    timestamptz not null default now(),
  status         text not null default 'novo'      -- novo | em contato | convertido | descartado
);
alter table public.meta_leads drop constraint if exists meta_leads_status_ok;
alter table public.meta_leads add constraint meta_leads_status_ok
  check (status in ('novo', 'em contato', 'convertido', 'descartado'));
create index if not exists meta_leads_recebido_idx on public.meta_leads (recebido_em desc);
create index if not exists meta_leads_client_idx on public.meta_leads (client_id);

-- ---------------------------------------------------------------------
-- QUEM VÊ O QUÊ
--   equipe (admin/team): vê contas de anúncio e leads; muda status do lead
--   admin: vincula conta de anúncio a cliente
--   cliente: vê só os leads do PRÓPRIO cliente
--   tokens: ninguém pelo app (só o servidor, com a chave secreta)
-- ---------------------------------------------------------------------
alter table public.meta_ad_accounts enable row level security;
alter table public.meta_user_tokens enable row level security;
alter table public.meta_leads       enable row level security;

drop policy if exists meta_contas_ler    on public.meta_ad_accounts;
drop policy if exists meta_contas_mudar  on public.meta_ad_accounts;
create policy meta_contas_ler on public.meta_ad_accounts for select
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
create policy meta_contas_mudar on public.meta_ad_accounts for update
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));

drop policy if exists meta_leads_ler   on public.meta_leads;
drop policy if exists meta_leads_mudar on public.meta_leads;
create policy meta_leads_ler on public.meta_leads for select
  using (
    exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team'))
    or (client_id is not null and exists (select 1 from public.profiles p
          where p.id = auth.uid() and p.role = 'client' and p.client_id = meta_leads.client_id))
  );
create policy meta_leads_mudar on public.meta_leads for update
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));

-- pelo app só se mexe no que é de mexer: o vínculo com o cliente e o status do lead
revoke update on public.meta_ad_accounts from authenticated;
revoke update on public.meta_leads from authenticated;
grant select on public.meta_ad_accounts to authenticated;
grant select on public.meta_leads to authenticated;
grant update (client_id) on public.meta_ad_accounts to authenticated;
grant update (status, client_id) on public.meta_leads to authenticated;
revoke all on public.meta_user_tokens from anon, authenticated;

-- conferência: deve devolver  3 | t
select count(*) as tabelas, bool_and(c.relrowsecurity) as rls
  from pg_class c
 where c.relname in ('meta_ad_accounts', 'meta_user_tokens', 'meta_leads');
