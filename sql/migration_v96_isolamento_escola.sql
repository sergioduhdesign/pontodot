-- v96 · ISOLAMENTO DA ESCOLA (doc mestre Monema, seção 26) — rodar ANTES de carregar qualquer conhecimento privado
-- Corrige: (1) funções SECURITY DEFINER aceitavam p_user/p_cliente de qualquer chamador; (2) EXECUTE aberto a anon (PUBLIC);
--          (3) pd_conhecimento legível por todo authenticated, inclusive escopo 'cliente'.
-- Reversível: basta recriar as versões do v94. Tabelas hoje vazias → sem impacto em dados.

-- 1) lições: o usuário vem do token; só admin/service_role pode pedir as de outra IA
create or replace function public.pd_licoes_da_ia(p_user uuid, p_skill text)
returns table (regra text, exemplo_bom text, exemplo_ruim text, comum boolean)
language sql stable security definer set search_path = public as $$
  select l.regra, l.exemplo_bom, l.exemplo_ruim, l.comum
    from pd_licoes l
   where l.status = 'aprovada' and l.skill_id = p_skill
     and (l.comum or l.ia_user_id = case when auth.role() = 'service_role' or pd_eh_admin() then p_user else auth.uid() end)
   order by l.comum, l.aprovada_em desc limit 25;
$$;

-- 2) busca: escopo 'cliente' só para admin/service_role até existir vínculo verificado de organização/cliente
create or replace function public.pd_buscar_conhecimento(
  p_texto text, p_embedding extensions.vector(384) default null,
  p_cliente text default null, p_skill text default null, p_limite int default 8)
returns table (id uuid, titulo text, tipo text, contexto text, texto text, fonte text, pontos float)
language sql stable security definer set search_path = public, extensions as $$
  with base as (
    select c.* from pd_conhecimento c
     where c.ativo
       and (c.escopo = 'casa'
            or (c.escopo = 'cliente' and p_cliente is not null and c.cliente = p_cliente
                and (auth.role() = 'service_role' or pd_eh_admin()))
            or (c.escopo = 'habilidade' and p_skill is not null and c.skill_id = p_skill))
  ), por_palavra as (
    select b.id, row_number() over (order by ts_rank(b.busca, websearch_to_tsquery('portuguese', p_texto)) desc) r
      from base b where b.busca @@ websearch_to_tsquery('portuguese', p_texto) order by r limit 30
  ), por_sentido as (
    select b.id, row_number() over (order by b.embedding <=> p_embedding) r
      from base b where p_embedding is not null and b.embedding is not null order by r limit 30
  ), fundido as (
    select id, sum(1.0 / (60 + r)) pontos from (select * from por_palavra union all select * from por_sentido) u group by id
  )
  select b.id, b.titulo, b.tipo, b.contexto, b.texto, b.fonte, f.pontos
    from fundido f join base b on b.id = f.id
   order by f.pontos desc limit greatest(1, least(p_limite, 20));
$$;

-- 3) EXECUTE: nada de anon/PUBLIC
revoke execute on function public.pd_licoes_da_ia(uuid, text) from public, anon;
revoke execute on function public.pd_buscar_conhecimento(text, extensions.vector, text, text, int) from public, anon;
grant  execute on function public.pd_licoes_da_ia(uuid, text) to authenticated, service_role;
grant  execute on function public.pd_buscar_conhecimento(text, extensions.vector, text, text, int) to authenticated, service_role;

-- 4) tabela: usuário comum lê só conhecimento 'casa'/'habilidade'; 'cliente' só admin
drop policy if exists pd_conh_ler on public.pd_conhecimento;
create policy pd_conh_ler on public.pd_conhecimento for select to authenticated
  using (pd_eh_admin() or (ativo and escopo <> 'cliente'));

-- TESTE (rodar como usuário comum, depois como anon): deve devolver só as próprias lições / negar anon
--   select * from pd_licoes_da_ia('<uuid de OUTRA pessoa>', 'LP_ESTRUTURA');   -- só comuns
