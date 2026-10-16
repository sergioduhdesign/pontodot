-- =====================================================================
-- Pontodot v75 — CANCELAR CONTA com 30 dias para voltar
-- Cancelar não apaga nada: marca a conta e dá 30 dias. Nesse prazo a
-- pessoa entra, vê o aviso e reativa com tudo (histórico, IA, memória).
-- Passou o prazo, a conta fica marcada para exclusão definitiva (um admin conclui).
-- Seguro rodar mais de uma vez.
-- =====================================================================
alter table public.profiles add column if not exists cancelada_em timestamptz;
alter table public.profiles add column if not exists exclusao_em  timestamptz;

create or replace function public.pd_cancelar_conta() returns jsonb
language plpgsql security definer set search_path = public as $$
declare me profiles; outros int;
begin
  select * into me from profiles where id = auth.uid();
  if me.id is null then raise exception 'sem sessão'; end if;
  if me.role = 'admin' then
    select count(*) into outros from profiles where role = 'admin' and id <> me.id and cancelada_em is null;
    if outros = 0 then raise exception 'você é o único admin: passe a administração para outra pessoa antes de cancelar'; end if;
  end if;
  update profiles set cancelada_em = now(), exclusao_em = now() + interval '30 days' where id = me.id;
  return jsonb_build_object('ok', true, 'exclusao_em', now() + interval '30 days');
end $$;

create or replace function public.pd_reativar_conta() returns jsonb
language plpgsql security definer set search_path = public as $$
declare n int;
begin
  update profiles set cancelada_em = null, exclusao_em = null
   where id = auth.uid() and cancelada_em is not null and exclusao_em > now();
  get diagnostics n = row_count;
  if n = 0 then raise exception 'o prazo de 30 dias já passou ou a conta não está cancelada'; end if;
  return jsonb_build_object('ok', true);
end $$;

grant execute on function public.pd_cancelar_conta(), public.pd_reativar_conta() to authenticated;
