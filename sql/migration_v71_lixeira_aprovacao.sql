-- =====================================================================
-- Pontodot v71 — LIXEIRA dos lotes enviados para aprovação
-- Excluir um lote guarda uma cópia completa (lote + peças + comentários)
-- e só então apaga. Restaurar devolve tudo como estava.
-- Equipe e admin. Seguro rodar mais de uma vez.
-- =====================================================================

create table if not exists public.approval_lixeira (
  id         uuid primary key default gen_random_uuid(),
  set_id     uuid not null,
  task_id    text,
  titulo     text,
  pecas      int  not null default 0,
  snapshot   jsonb not null,
  excluido_por uuid default auth.uid(),
  excluido_em  timestamptz not null default now()
);
alter table public.approval_lixeira enable row level security;
revoke all on public.approval_lixeira from anon, authenticated;   -- só pelas funções abaixo

create or replace function public.pd_equipe() returns boolean
language sql stable security definer set search_path = public as $$
  select exists (select 1 from profiles p where p.id = auth.uid() and p.role in ('admin','team'));
$$;

create or replace function public.pd_excluir_lote(p_set uuid) returns jsonb
language plpgsql security definer set search_path = public as $$
declare s jsonb; its jsonb; cms jsonb; n int; tk text; ti text;
begin
  if not pd_equipe() then raise exception 'só a equipe exclui lotes'; end if;
  select to_jsonb(x) into s from approval_sets x where x.id = p_set;
  if s is null then raise exception 'lote não encontrado'; end if;
  select coalesce(jsonb_agg(to_jsonb(i) order by i.position), '[]'::jsonb) into its from approval_items i where i.set_id = p_set;
  select coalesce(jsonb_agg(to_jsonb(c)), '[]'::jsonb) into cms from approval_comments c
    where c.item_id in (select id from approval_items where set_id = p_set);
  n := jsonb_array_length(its);
  tk := s->>'task_id'; ti := s->>'title';
  insert into approval_lixeira(set_id, task_id, titulo, pecas, snapshot)
    values (p_set, tk, ti, n, jsonb_build_object('set', s, 'items', its, 'comments', cms));
  delete from approval_comments where item_id in (select id from approval_items where set_id = p_set);
  delete from approval_items where set_id = p_set;
  delete from approval_sets where id = p_set;
  return jsonb_build_object('ok', true, 'pecas', n, 'task_id', tk);
end $$;

create or replace function public.pd_lixeira_lista() returns table (id uuid, set_id uuid, task_id text, titulo text, pecas int, excluido_em timestamptz)
language sql stable security definer set search_path = public as $$
  select l.id, l.set_id, l.task_id, l.titulo, l.pecas, l.excluido_em from approval_lixeira l
  where pd_equipe() order by l.excluido_em desc limit 100;
$$;

create or replace function public.pd_restaurar_lote(p_id uuid) returns jsonb
language plpgsql security definer set search_path = public as $$
declare l approval_lixeira; it jsonb; cm jsonb;
begin
  if not pd_equipe() then raise exception 'só a equipe restaura lotes'; end if;
  select * into l from approval_lixeira where id = p_id;
  if l.id is null then raise exception 'item não está mais na lixeira'; end if;
  insert into approval_sets select * from jsonb_populate_record(null::approval_sets, l.snapshot->'set');
  for it in select * from jsonb_array_elements(l.snapshot->'items') loop
    insert into approval_items select * from jsonb_populate_record(null::approval_items, it);
  end loop;
  for cm in select * from jsonb_array_elements(l.snapshot->'comments') loop
    insert into approval_comments select * from jsonb_populate_record(null::approval_comments, cm);
  end loop;
  delete from approval_lixeira where id = p_id;
  return jsonb_build_object('ok', true, 'task_id', l.task_id);
end $$;

create or replace function public.pd_esvaziar_lixeira_item(p_id uuid) returns void
language plpgsql security definer set search_path = public as $$
begin
  if not exists (select 1 from profiles p where p.id = auth.uid() and p.role = 'admin') then raise exception 'só admin apaga de vez'; end if;
  delete from approval_lixeira where id = p_id;
end $$;

grant execute on function public.pd_equipe(), public.pd_excluir_lote(uuid), public.pd_lixeira_lista(),
  public.pd_restaurar_lote(uuid), public.pd_esvaziar_lixeira_item(uuid) to authenticated;
