-- v106 · Consumo da IA: cada resposta grava os tokens; o painel Admin mostra em tempo quase real.
create table if not exists public.sd_ia_uso (
  id            bigint generated always as identity primary key,
  em            timestamptz not null default now(),
  user_id       text,
  canal         text not null default 'desk',     -- desk | zap | outro
  modelo        text,
  entrada       int not null default 0,
  saida         int not null default 0,
  cache_leitura int not null default 0,
  cache_escrita int not null default 0
);
create index if not exists sd_ia_uso_em_idx on public.sd_ia_uso (em desc);
alter table public.sd_ia_uso enable row level security;
drop policy if exists sd_ia_uso_admin on public.sd_ia_uso;
create policy sd_ia_uso_admin on public.sd_ia_uso for select to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));

create or replace function public.sd_ia_consumo()
returns jsonb language plpgsql security definer set search_path = public as $$
declare
  fuso text := 'America/Sao_Paulo';
  ini_hoje timestamptz := date_trunc('day', now() at time zone 'America/Sao_Paulo') at time zone 'America/Sao_Paulo';
  r jsonb;
begin
  if not exists (select 1 from profiles where id = auth.uid() and role = 'admin') then raise exception 'só administrador'; end if;
  select jsonb_build_object(
    'hoje',   (select jsonb_build_object('chamadas', count(*), 'entrada', coalesce(sum(entrada),0), 'saida', coalesce(sum(saida),0), 'cache', coalesce(sum(cache_leitura),0)) from sd_ia_uso where em >= ini_hoje),
    'semana', (select jsonb_build_object('chamadas', count(*), 'entrada', coalesce(sum(entrada),0), 'saida', coalesce(sum(saida),0), 'cache', coalesce(sum(cache_leitura),0)) from sd_ia_uso where em >= now() - interval '7 days'),
    'mes',    (select jsonb_build_object('chamadas', count(*), 'entrada', coalesce(sum(entrada),0), 'saida', coalesce(sum(saida),0), 'cache', coalesce(sum(cache_leitura),0)) from sd_ia_uso where em >= date_trunc('month', now() at time zone fuso) at time zone fuso),
    'por_canal', coalesce((select jsonb_agg(x) from (select canal, count(*) chamadas, sum(entrada) entrada, sum(saida) saida from sd_ia_uso where em >= now() - interval '30 days' group by canal order by sum(entrada+saida) desc) x), '[]'::jsonb),
    'por_pessoa', coalesce((select jsonb_agg(x) from (select coalesce(p.name, u.user_id, '—') pessoa, count(*) chamadas, sum(u.entrada) entrada, sum(u.saida) saida from sd_ia_uso u left join profiles p on p.id::text = u.user_id where u.em >= now() - interval '30 days' group by 1 order by sum(u.entrada+u.saida) desc limit 10) x), '[]'::jsonb),
    'por_dia', coalesce((select jsonb_agg(x order by x.dia) from (select to_char(em at time zone fuso, 'DD/MM') dia, sum(entrada+saida) total from sd_ia_uso where em >= now() - interval '14 days' group by 1) x), '[]'::jsonb),
    'ultimas', coalesce((select jsonb_agg(x) from (select to_char(em at time zone fuso, 'DD/MM HH24:MI') quando, canal, modelo, entrada, saida from sd_ia_uso order by em desc limit 8) x), '[]'::jsonb)
  ) into r;
  return r;
end $$;
revoke all on function public.sd_ia_consumo() from public, anon;
grant execute on function public.sd_ia_consumo() to authenticated;
