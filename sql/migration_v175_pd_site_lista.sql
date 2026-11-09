-- =====================================================================
-- Pontodot v175 — SITES e LPs de todos os clientes (painel "Sites" + súmula no perfil).
-- Um cliente pode ter vários (site, LPs de produto, área de clientes, blog).
-- O que a esteira publica continua em pd_sites (um por cliente): o item marcado
-- como `principal` aqui é a ÚNICA fonte; o app espelha tipo/url em pd_sites.
-- Seguro rodar mais de uma vez.
-- =====================================================================
create table if not exists public.pd_site_lista (
  id             uuid primary key default gen_random_uuid(),
  client_id      uuid not null references public.clients(id) on delete cascade,
  nome           text not null,                                  -- "Site", "LP Residencial X"
  papel          text not null default 'site'      check (papel in ('site','lp','area_clientes','blog')),
  plataforma     text not null default 'html'      check (plataforma in ('wp','html','wix','outro')),
  url            text,
  hospedagem     text,                                           -- hostgator | wix | desconhecida | outra
  repo           text,                                           -- GitHub: dono/repositorio
  principal      boolean not null default false,                 -- o que a PD publica sozinha
  status         text not null default 'desconhecido' check (status in ('no_ar','em_obra','fora_do_ar','desconhecido')),
  cms            text not null default 'pendente'  check (cms in ('pendente','conectado','erro','nao_precisa')),
  backend        text not null default 'pendente'  check (backend in ('pendente','ok','nao_precisa')),
  area_clientes  text not null default 'pendente'  check (area_clientes in ('pendente','ok','nao_precisa')),
  revisao        text not null default 'pendente'  check (revisao in ('pendente','ok','nao_precisa')),
  responsavel    text,                                           -- ex.: Juan, cliente
  obs            text,
  criado_em      timestamptz not null default now(),
  atualizado_em  timestamptz not null default now()
);
create unique index if not exists pd_site_lista_um_principal on public.pd_site_lista (client_id) where principal;
create index if not exists pd_site_lista_cliente on public.pd_site_lista (client_id);

alter table public.pd_site_lista enable row level security;
drop policy if exists pd_site_lista_equipe_ler on public.pd_site_lista;
create policy pd_site_lista_equipe_ler on public.pd_site_lista for select
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
drop policy if exists pd_site_lista_equipe_esc on public.pd_site_lista;
create policy pd_site_lista_equipe_esc on public.pd_site_lista for all
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
revoke all on public.pd_site_lista from anon;
grant select, insert, update, delete on public.pd_site_lista to authenticated;

-- mesma data de alteração automática das outras tabelas
create or replace function public.pd_site_lista_toca() returns trigger language plpgsql as $$
begin new.atualizado_em = now(); return new; end $$;
drop trigger if exists pd_site_lista_toca on public.pd_site_lista;
create trigger pd_site_lista_toca before update on public.pd_site_lista for each row execute function public.pd_site_lista_toca();

-- Já existe um site conectado em pd_sites? Entra na lista como principal, sem duplicar.
insert into public.pd_site_lista (client_id, nome, papel, plataforma, url, principal, status, cms)
select s.client_id, 'Site', 'site',
       case s.tipo when 'wp' then 'wp' else 'html' end,
       s.url, true, case when s.url is not null then 'no_ar' else 'desconhecido' end,
       case when s.ativo then 'conectado' else 'pendente' end
  from public.pd_sites s
 where s.tipo is not null
   and not exists (select 1 from public.pd_site_lista l where l.client_id = s.client_id and l.principal);

-- desfazer: drop table public.pd_site_lista;  (pd_sites não é tocada)
