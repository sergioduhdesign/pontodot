-- v109 — código do espaço (tela de login da home) + medidores da home pública
-- 1) Espaços: o "código" que a pessoa digita uma vez no login (ex.: sd). Só valida se existe.
create table if not exists public.pd_espacos (
  codigo text primary key check (codigo = lower(codigo) and codigo ~ '^[a-z0-9-]{2,30}$'),
  nome text,
  ativo boolean not null default true,
  criado_em timestamptz not null default now()
);
alter table public.pd_espacos enable row level security;
drop policy if exists "espacos_admin" on public.pd_espacos;
create policy "espacos_admin" on public.pd_espacos for all to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));
insert into public.pd_espacos (codigo, nome) values ('sd', 'SD / Pontodot') on conflict (codigo) do nothing;

create or replace function public.pd_espaco_ok(p_codigo text) returns boolean
language sql security definer set search_path = public stable as $$
  select exists (select 1 from public.pd_espacos where codigo = lower(btrim(p_codigo)) and ativo);
$$;
grant execute on function public.pd_espaco_ok(text) to anon, authenticated;

-- 2) Medidores: um evento por linha (visita, rolagem, clique, fila, login...). Sem cookie, sem IP.
create table if not exists public.pd_eventos (
  id bigint generated always as identity primary key,
  criado_em timestamptz not null default now(),
  sessao text not null,
  evento text not null,
  detalhe text,
  caminho text,
  referrer text,
  utm_source text, utm_medium text, utm_campaign text,
  largura int
);
create index if not exists pd_eventos_dia_idx on public.pd_eventos (criado_em desc, evento);
alter table public.pd_eventos enable row level security;
drop policy if exists "eventos_inserir" on public.pd_eventos;
create policy "eventos_inserir" on public.pd_eventos for insert to anon, authenticated
  with check (length(sessao) between 1 and 60 and length(evento) between 1 and 40);
drop policy if exists "eventos_admin" on public.pd_eventos;
create policy "eventos_admin" on public.pd_eventos for select to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));
grant insert on public.pd_eventos to anon, authenticated;
grant select on public.pd_eventos to authenticated;

-- resumo por dia (só admin enxerga, pela policy acima)
create or replace view public.pd_eventos_resumo with (security_invoker = true) as
  select date_trunc('day', criado_em at time zone 'America/Sao_Paulo')::date as dia,
         evento, coalesce(detalhe,'') as detalhe,
         count(*) as total, count(distinct sessao) as pessoas
  from public.pd_eventos group by 1,2,3;
grant select on public.pd_eventos_resumo to authenticated;
