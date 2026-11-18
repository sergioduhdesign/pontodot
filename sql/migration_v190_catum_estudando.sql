-- =====================================================================
-- Pontodot v190 — CATUM: quem está estudando na Escola agora.
-- Devolve só o personagem (IA) e o curso das inscrições ATIVAS — nada privado.
-- Sem esta função, Catum mostra na escola as IAs matriculadas (pd_escola_alunos), sem o curso.
-- Seguro rodar mais de uma vez.
-- =====================================================================
create or replace function public.catum_estudando()
returns table (personagem text, curso_id text, termina_em timestamptz)
language sql stable security definer set search_path = public as $$
  select j.personagem, i.curso_id, max(i.termina_em) as termina_em
    from pd_inscricoes i
    join pd_jeito j on j.user_id = i.user_id
   where i.status = 'ativa' and j.personagem is not null and j.personagem <> ''
   group by j.personagem, i.curso_id;
$$;
revoke all on function public.catum_estudando() from public;
grant execute on function public.catum_estudando() to authenticated;

-- desfazer: drop function public.catum_estudando();
