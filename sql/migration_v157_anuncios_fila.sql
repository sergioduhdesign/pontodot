-- v157 · ANÚNCIOS NA MESMA FILA do aprovador
-- Rode UMA vez no Supabase (SQL Editor > Run). Pode rodar de novo.

-- 1) destino do anúncio: em qual conjunto de anúncios as peças aprovadas de cada cliente/produto entram
create table if not exists public.pd_anuncio_destino (
  id            uuid primary key default gen_random_uuid(),
  client_id     uuid not null references public.clients(id) on delete cascade,
  product_id    uuid references public.products(id) on delete cascade,
  conta         text not null,                 -- act_123...
  campaign_id   text,
  adset_id      text not null,                 -- conjunto de anúncios onde o anúncio nasce
  link          text,                          -- para onde o clique leva (se não usa formulário)
  cta           text not null default 'LEARN_MORE',
  lead_form_id  text,                          -- formulário instantâneo (lead) da Meta, se usar
  atualizado_em timestamptz not null default now()
);
create index if not exists pd_anuncio_destino_cli_idx on public.pd_anuncio_destino (client_id);
alter table public.pd_anuncio_destino enable row level security;
drop policy if exists pd_anuncio_destino_equipe on public.pd_anuncio_destino;
create policy pd_anuncio_destino_equipe on public.pd_anuncio_destino for all
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));
grant select, insert, update, delete on public.pd_anuncio_destino to authenticated;

-- 2) "anuncio" passa a ser um tipo de peça: libera qualquer trava de valores antiga
do $$
declare r record;
begin
  for r in select c.conrelid::regclass as tabela, c.conname
             from pg_constraint c
            where c.contype = 'c'
              and c.conrelid in ('public.approval_items'::regclass, 'public.scheduled_posts'::regclass, 'public.scheduled_post_targets'::regclass)
              and (pg_get_constraintdef(c.oid) ilike '%kind%' or pg_get_constraintdef(c.oid) ilike '%post_type%' or pg_get_constraintdef(c.oid) ilike '%placement%')
  loop
    execute format('alter table %s drop constraint %I', r.tabela, r.conname);
  end loop;
end $$;

select to_regclass('public.pd_anuncio_destino') is not null as tabela_ok;
