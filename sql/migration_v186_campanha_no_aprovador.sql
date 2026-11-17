-- Detalhes da campanha no aprovador (cliente vê e sugere ajuste) — rodar no Supabase (SQL Editor)
alter table public.approval_sets add column if not exists campanha jsonb;
alter table public.approval_sets add column if not exists campanha_ajustes jsonb not null default '[]'::jsonb;

-- Lotes de campanha já enviados: puxa o setup salvo na mesma tarefa (se existir)
do $$ begin
  if to_regclass('public.pd_campanha_setup') is not null then
    update public.approval_sets s set campanha = (
      select c.dados from public.pd_campanha_setup c where c.task_id = s.task_id order by c.criado_em desc limit 1)
    where s.campanha is null and s.task_id is not null
      and exists (select 1 from public.pd_campanha_setup c where c.task_id = s.task_id);
  end if;
end $$;

-- Link do cliente (sem login): lê a campanha só com o token do lote, e só se o lote já foi liberado
create or replace function public.get_campanha_by_token(p_token text) returns jsonb
language sql security definer set search_path = public stable as $$
  select case when s.campanha is null then null
    else jsonb_build_object('campanha', s.campanha, 'ajustes', coalesce(s.campanha_ajustes, '[]'::jsonb)) end
  from public.approval_sets s
  where s.share_token::text = p_token and s.status <> 'rascunho' limit 1;
$$;

-- Cliente sugere ajuste em uma seção (campanha, persona, publicos, anuncios)
create or replace function public.campanha_ajuste_by_token(p_token text, p_secao text, p_texto text, p_guest text default null) returns jsonb
language plpgsql security definer set search_path = public as $$
declare v_id uuid; v_novo jsonb;
begin
  if p_secao not in ('campanha','persona','publicos','anuncios') then raise exception 'seção inválida'; end if;
  if coalesce(btrim(p_texto), '') = '' or length(p_texto) > 2000 then raise exception 'texto inválido'; end if;
  select s.id into v_id from public.approval_sets s where s.share_token::text = p_token and s.status <> 'rascunho' limit 1;
  if v_id is null then raise exception 'link inválido'; end if;
  v_novo := jsonb_build_object('id', gen_random_uuid(), 'secao', p_secao, 'texto', btrim(p_texto),
    'por', left(coalesce(nullif(btrim(p_guest), ''), 'Cliente'), 80), 'em', now(), 'resolvido', false);
  update public.approval_sets set campanha_ajustes = coalesce(campanha_ajustes, '[]'::jsonb) || v_novo where id = v_id;
  return v_novo;
end $$;

grant execute on function public.get_campanha_by_token(text) to anon, authenticated;
grant execute on function public.campanha_ajuste_by_token(text, text, text, text) to anon, authenticated;
