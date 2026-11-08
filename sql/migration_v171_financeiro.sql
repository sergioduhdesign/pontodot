-- =====================================================================
-- Pontodot v171 — FINANCEIRO (Casa e Empresa)
-- Só admin enxerga. Outra pessoa só entra se o admin liberar (fin_acessos)
-- E ela confirmar a senha de novo (fin_destrancar) — checado no banco,
-- não só na tela. Seguro rodar mais de uma vez.
-- =====================================================================

create extension if not exists pgcrypto with schema extensions;

-- ---------- quem pode ----------
create table if not exists public.fin_acessos (
  user_id   uuid not null references auth.users(id) on delete cascade,
  area      text not null check (area in ('casa','empresa')),
  editar    boolean not null default false,
  criado_em timestamptz not null default now(),
  primary key (user_id, area)
);
alter table public.fin_acessos enable row level security;

-- a "porta": depois de confirmar a senha, a pessoa fica liberada por 30 min
create table if not exists public.fin_sessao (
  user_id       uuid primary key references auth.users(id) on delete cascade,
  liberado_ate  timestamptz,
  erros         int not null default 0,
  bloqueado_ate timestamptz
);
alter table public.fin_sessao enable row level security;
revoke all on public.fin_sessao from anon, authenticated;   -- só as funções abaixo mexem

create or replace function public.fin_admin()
returns boolean language sql stable security definer set search_path = public as $$
  select exists (select 1 from profiles p where p.id = auth.uid() and p.role = 'admin')
$$;

create or replace function public.fin_pode(amb text, escrita boolean default false)
returns boolean language sql stable security definer set search_path = public as $$
  select public.fin_admin() or exists (
    select 1 from fin_acessos a join fin_sessao s on s.user_id = a.user_id
    where a.user_id = auth.uid() and a.area = amb and (not escrita or a.editar) and s.liberado_ate > now())
$$;

-- confirma a senha NO BANCO (5 erros = 15 min de bloqueio)
create or replace function public.fin_destrancar(senha text)
returns jsonb language plpgsql security definer set search_path = public, extensions, auth as $$
declare s fin_sessao; ok boolean; ate timestamptz;
begin
  if auth.uid() is null then return jsonb_build_object('ok', false, 'msg', 'Entre na sua conta.'); end if;
  insert into fin_sessao (user_id) values (auth.uid()) on conflict (user_id) do nothing;
  select * into s from fin_sessao where user_id = auth.uid() for update;
  if s.bloqueado_ate is not null and s.bloqueado_ate > now() then
    return jsonb_build_object('ok', false, 'msg', 'Muitas tentativas. Tente de novo às ' || to_char(s.bloqueado_ate at time zone 'America/Sao_Paulo', 'HH24:MI') || '.');
  end if;
  select (u.encrypted_password = crypt(senha, u.encrypted_password)) into ok from auth.users u where u.id = auth.uid();
  if not coalesce(ok, false) then
    update fin_sessao set erros = case when erros + 1 >= 5 then 0 else erros + 1 end,
                          bloqueado_ate = case when erros + 1 >= 5 then now() + interval '15 minutes' else bloqueado_ate end
      where user_id = auth.uid();
    return jsonb_build_object('ok', false, 'msg', 'A senha não confere.');
  end if;
  ate := now() + interval '30 minutes';
  update fin_sessao set liberado_ate = ate, erros = 0, bloqueado_ate = null where user_id = auth.uid();
  return jsonb_build_object('ok', true, 'ate', ate);
end $$;
revoke all on function public.fin_destrancar(text) from public, anon;
grant execute on function public.fin_destrancar(text) to authenticated;

create or replace function public.fin_trancar()
returns void language sql security definer set search_path = public as $$
  update fin_sessao set liberado_ate = null where user_id = auth.uid()
$$;
revoke all on function public.fin_trancar() from public, anon;
grant execute on function public.fin_trancar() to authenticated;

-- o que a pessoa pode (a tela usa pra decidir o que mostrar)
create or replace function public.fin_meu_acesso()
returns jsonb language sql stable security definer set search_path = public as $$
  select jsonb_build_object(
    'admin', public.fin_admin(),
    'liberado_ate', (select liberado_ate from fin_sessao where user_id = auth.uid()),
    'areas', coalesce((select jsonb_agg(jsonb_build_object('area', area, 'editar', editar)) from fin_acessos where user_id = auth.uid()), '[]'::jsonb))
$$;
revoke all on function public.fin_meu_acesso() from public, anon;
grant execute on function public.fin_meu_acesso() to authenticated;

drop policy if exists fin_acessos_admin on public.fin_acessos;
create policy fin_acessos_admin on public.fin_acessos for all to authenticated
  using (public.fin_admin()) with check (public.fin_admin());

-- ---------- os lançamentos (o coração: o que era linha da planilha) ----------
create table if not exists public.fin_lancamentos (
  id             uuid primary key default gen_random_uuid(),
  ambiente       text not null check (ambiente in ('casa','empresa')),
  mes            date not null,                              -- sempre dia 1 do mês
  sentido        text not null check (sentido in ('entrada','saida')),
  grupo          text,                                       -- fixo | variavel | com_nf | sem_nf | saldo
  dia            int check (dia between 1 and 31),
  descricao      text not null,                              -- cliente (entrada) ou "o que é" (saída)
  parcela        text,                                       -- "5de14", "mensal", "fixo"…
  parcela_n      int,
  parcela_de     int,
  forma          text,                                       -- transf, PJ, cartão…
  valor          numeric(14,2) not null,
  obs            text,
  vendedor       text,
  situacao       text,                                       -- texto original: OK, SOLICITADO, ENVIADO…
  pago           boolean not null default false,
  conta          boolean not null default true,              -- false = anotação (não entra na soma, como na planilha)
  externo_id     text,                                       -- pra ligar com banco / NF / cobrança depois
  origem         text,                                       -- "aba|linha" da planilha (importação sem duplicar)
  criado_em      timestamptz not null default now(),
  atualizado_em  timestamptz not null default now()
);
create index if not exists fin_lanc_amb_mes on public.fin_lancamentos (ambiente, mes);
create unique index if not exists fin_lanc_origem on public.fin_lancamentos (ambiente, origem);
alter table public.fin_lancamentos enable row level security;

create or replace function public.fin_toca_atualizado() returns trigger language plpgsql as $$
begin new.atualizado_em := now(); return new; end $$;
drop trigger if exists fin_lanc_toca on public.fin_lancamentos;
create trigger fin_lanc_toca before update on public.fin_lancamentos for each row execute function public.fin_toca_atualizado();

drop policy if exists fin_lanc_ler on public.fin_lancamentos;
create policy fin_lanc_ler on public.fin_lancamentos for select to authenticated using (public.fin_pode(ambiente));
drop policy if exists fin_lanc_inserir on public.fin_lancamentos;
create policy fin_lanc_inserir on public.fin_lancamentos for insert to authenticated with check (public.fin_pode(ambiente, true));
drop policy if exists fin_lanc_alterar on public.fin_lancamentos;
create policy fin_lanc_alterar on public.fin_lancamentos for update to authenticated using (public.fin_pode(ambiente, true)) with check (public.fin_pode(ambiente, true));
drop policy if exists fin_lanc_apagar on public.fin_lancamentos;
create policy fin_lanc_apagar on public.fin_lancamentos for delete to authenticated using (public.fin_pode(ambiente, true));

-- ---------- o resto da planilha (rascunhos, quinzenas, repasses, impostos, sócios…) ----------
create table if not exists public.fin_notas_planilha (
  id        bigint generated always as identity primary key,
  ambiente  text not null check (ambiente in ('casa','empresa')),
  mes       date not null,
  lin       int not null,
  texto     text not null
);
create index if not exists fin_notas_amb_mes on public.fin_notas_planilha (ambiente, mes, lin);
alter table public.fin_notas_planilha enable row level security;
drop policy if exists fin_notas_ler on public.fin_notas_planilha;
create policy fin_notas_ler on public.fin_notas_planilha for select to authenticated using (public.fin_pode(ambiente));
drop policy if exists fin_notas_admin on public.fin_notas_planilha;
create policy fin_notas_admin on public.fin_notas_planilha for all to authenticated using (public.fin_admin()) with check (public.fin_admin());

-- ---------- custos pequenos de IA e ferramentas ----------
create table if not exists public.fin_custos_ia (
  id          uuid primary key default gen_random_uuid(),
  data        date not null default current_date,
  ferramenta  text not null,
  descricao   text,
  valor       numeric(14,2) not null,
  moeda       text not null default 'BRL' check (moeda in ('BRL','USD')),
  criado_em   timestamptz not null default now()
);
alter table public.fin_custos_ia enable row level security;
drop policy if exists fin_ia_ler on public.fin_custos_ia;
create policy fin_ia_ler on public.fin_custos_ia for select to authenticated using (public.fin_pode('empresa'));
drop policy if exists fin_ia_esc on public.fin_custos_ia;
create policy fin_ia_esc on public.fin_custos_ia for all to authenticated using (public.fin_pode('empresa', true)) with check (public.fin_pode('empresa', true));
