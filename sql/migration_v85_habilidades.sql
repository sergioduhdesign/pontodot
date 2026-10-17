-- =====================================================================
-- Pontodot v85 — HABILIDADES, HABILITAÇÕES E CONTRIBUIÇÕES (piloto)
-- Habitante (cânone) ≠ habilidade (receita) ≠ habilitação (consegue executar?).
-- Seguro rodar mais de uma vez. Nada aqui apaga ou altera dado existente.
-- =====================================================================

-- 1) HABILIDADE: a receita versionada de um trabalho
create table if not exists public.pd_habilidades (
  skill_id        text not null,                    -- ex.: LP_ESTRUTURA
  versao          int  not null default 1,
  nome            text not null,
  finalidade      text not null,
  entradas        jsonb not null default '[]'::jsonb,   -- o que precisa chegar
  metodo          text  not null default '',            -- passos e o que a IA decide sozinha
  exemplos        jsonb not null default '[]'::jsonb,   -- entregas aprovadas, erros conhecidos
  ferramentas     jsonb not null default '[]'::jsonb,   -- ações necessárias (vazio = só texto)
  saida           jsonb not null default '{}'::jsonb,   -- estrutura/formato da entrega
  criterios       jsonb not null default '[]'::jsonb,   -- critérios observáveis de revisão
  limites         text  not null default '',            -- o que exige humano/confirmação
  responsavel     uuid references public.profiles(id) on delete set null,
  ativa           boolean not null default true,
  criada_em       timestamptz not null default now(),
  primary key (skill_id, versao)
);

-- 2) HABILITAÇÃO: este habitante, como executor, realmente faz esta habilidade?
create table if not exists public.pd_habilitacoes (
  id              uuid primary key default gen_random_uuid(),
  personagem      text not null,                    -- id do cânone: nami, kiro...
  skill_id        text not null,
  versao          int  not null,
  ferramentas_ok  boolean not null default true,    -- ferramentas exigidas estão disponíveis
  avaliacao       text not null default 'nao_testada'
                  check (avaliacao in ('nao_testada','em_teste','aprovada','reprovada')),
  evidencias      jsonb not null default '[]'::jsonb,   -- referências dos testes feitos
  avaliada_por    uuid references public.profiles(id) on delete set null,
  avaliada_em     timestamptz,
  criada_em       timestamptz not null default now(),
  unique (personagem, skill_id, versao),
  foreign key (skill_id, versao) references public.pd_habilidades(skill_id, versao) on delete cascade
);

-- 3) CONTRIBUIÇÃO: um habitante trabalhando num pedaço de uma tarefa
create table if not exists public.pd_contribuicoes (
  id              uuid primary key default gen_random_uuid(),
  task_id         text not null,
  personagem      text not null,
  skill_id        text not null,
  versao          int  not null,
  papel           text not null default 'executa' check (papel in ('conduz','executa','revisa')),
  status          text not null default 'pronta'
                  check (status in ('pronta','em_producao','revisao','concluida','falha','cancelada','assumida_por_humano')),
  entrada_refs    jsonb not null default '{}'::jsonb,   -- arquivos e versões de entrada
  decisoes        jsonb not null default '[]'::jsonb,   -- decisões aprovadas que viajam junto
  lacunas         jsonb not null default '[]'::jsonb,
  saida_refs      jsonb not null default '{}'::jsonb,   -- resultado, arquivos, evidências de revisão
  origem_id       uuid references public.pd_contribuicoes(id) on delete set null,  -- passagem: de quem recebeu
  chave           text not null,                        -- idempotência: repetir o Play não duplica
  criada_por      uuid references public.profiles(id) on delete set null,
  criada_em       timestamptz not null default now(),
  atualizada_em   timestamptz not null default now(),
  unique (chave),
  foreign key (skill_id, versao) references public.pd_habilidades(skill_id, versao)
);
create index if not exists pd_contrib_task_idx on public.pd_contribuicoes(task_id);

-- 4) SEGURANÇA: todo mundo logado lê; só admin escreve receita e habilitação;
--    contribuição só nasce pela função (que checa elegibilidade).
alter table public.pd_habilidades   enable row level security;
alter table public.pd_habilitacoes  enable row level security;
alter table public.pd_contribuicoes enable row level security;

drop policy if exists pd_hab_ler  on public.pd_habilidades;
create policy pd_hab_ler  on public.pd_habilidades  for select to authenticated using (true);
drop policy if exists pd_habil_ler on public.pd_habilitacoes;
create policy pd_habil_ler on public.pd_habilitacoes for select to authenticated using (true);
drop policy if exists pd_contrib_ler on public.pd_contribuicoes;
create policy pd_contrib_ler on public.pd_contribuicoes for select to authenticated using (true);

drop policy if exists pd_hab_admin on public.pd_habilidades;
create policy pd_hab_admin on public.pd_habilidades for all to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));
drop policy if exists pd_habil_admin on public.pd_habilitacoes;
create policy pd_habil_admin on public.pd_habilitacoes for all to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));
revoke insert, update, delete on public.pd_contribuicoes from anon, authenticated;

-- 5) ELEGIBILIDADE: quem PODE fazer esta habilidade agora (aprovada + ferramentas ok).
--    Sem candidato, a tela oferece atribuição humana — nunca simula habilidade.
create or replace function public.pd_elegiveis(p_skill text, p_versao int default null)
returns table (personagem text, versao int)
language sql stable security definer set search_path = public as $$
  select h.personagem, h.versao
    from pd_habilitacoes h
    join pd_habilidades s on s.skill_id = h.skill_id and s.versao = h.versao and s.ativa
   where h.skill_id = p_skill
     and h.avaliacao = 'aprovada' and h.ferramentas_ok
     and (p_versao is null or h.versao = p_versao)
   order by h.versao desc, h.personagem;
$$;
grant execute on function public.pd_elegiveis(text,int) to authenticated;

-- 6) CRIAR CONTRIBUIÇÃO: idempotente e com checagem de elegibilidade.
create or replace function public.pd_criar_contribuicao(
  p_task text, p_personagem text, p_skill text, p_versao int, p_papel text,
  p_entrada jsonb default '{}'::jsonb, p_decisoes jsonb default '[]'::jsonb,
  p_lacunas jsonb default '[]'::jsonb, p_origem uuid default null, p_chave text default null)
returns public.pd_contribuicoes
language plpgsql security definer set search_path = public as $$
declare
  k text := coalesce(nullif(p_chave,''), md5(p_task||'|'||p_personagem||'|'||p_skill||'|'||p_versao||'|'||p_papel||'|'||coalesce(p_origem::text,'')));
  r public.pd_contribuicoes;
begin
  if auth.uid() is null then raise exception 'precisa estar logado'; end if;
  select * into r from pd_contribuicoes where chave = k;
  if found then return r; end if;                           -- repetiu: devolve a mesma, não duplica
  if not exists (select 1 from pd_elegiveis(p_skill, p_versao) e where e.personagem = p_personagem) then
    raise exception 'sem habilitação aprovada: % não está habilitado(a) para % v%', p_personagem, p_skill, p_versao;
  end if;
  insert into pd_contribuicoes (task_id, personagem, skill_id, versao, papel, entrada_refs, decisoes, lacunas, origem_id, chave, criada_por)
  values (p_task, p_personagem, p_skill, p_versao, p_papel, p_entrada, p_decisoes, p_lacunas, p_origem, k, auth.uid())
  on conflict (chave) do nothing
  returning * into r;
  if r.id is null then select * into r from pd_contribuicoes where chave = k; end if;
  return r;
end $$;
grant execute on function public.pd_criar_contribuicao(text,text,text,int,text,jsonb,jsonb,jsonb,uuid,text) to authenticated;

-- 7) ATUALIZAR CONTRIBUIÇÃO (só o estado e a saída; o humano pode assumir)
create or replace function public.pd_atualizar_contribuicao(p_id uuid, p_status text, p_saida jsonb default null)
returns public.pd_contribuicoes
language plpgsql security definer set search_path = public as $$
declare r public.pd_contribuicoes;
begin
  if auth.uid() is null then raise exception 'precisa estar logado'; end if;
  if p_status not in ('pronta','em_producao','revisao','concluida','falha','cancelada','assumida_por_humano') then
    raise exception 'estado inválido'; end if;
  if p_status = 'concluida' and (p_saida is null or p_saida = '{}'::jsonb)
     and not exists (select 1 from pd_contribuicoes where id = p_id and saida_refs <> '{}'::jsonb) then
    raise exception 'não declara conclusão sem resultado ou evidência'; end if;
  update pd_contribuicoes
     set status = p_status, saida_refs = coalesce(p_saida, saida_refs), atualizada_em = now()
   where id = p_id returning * into r;
  return r;
end $$;
grant execute on function public.pd_atualizar_contribuicao(uuid,text,jsonb) to authenticated;

-- 8) SEMENTES DO PILOTO (landing page: Nami conduz, Kiro revisa).
--    Entram como 'nao_testada': NÃO ficam elegíveis até um admin aprovar depois dos testes.
insert into public.pd_habilidades (skill_id, versao, nome, finalidade, entradas, metodo, ferramentas, saida, criterios, limites)
values
('LP_ESTRUTURA', 1, 'Estrutura de landing page',
 'Organizar as seções de uma landing page, explicar a função de cada uma e propor os textos, marcando as lacunas.',
 '["objetivo","público","oferta","provas e fatos confirmados","referência aprovada","CTA disponível"]'::jsonb,
 E'1. Conferir se todas as entradas chegaram; se faltar algo, listar como lacuna e seguir só com o que há.\n2. Montar a ordem das seções (promessa, prova, oferta, objeções, CTA) justificando a função de cada uma.\n3. Propor o texto de cada seção usando apenas fatos confirmados; marcar [CONFIRMAR] onde faltar sustentação.\n4. Respeitar a marca do cliente; o estilo do habitante aparece na colaboração, não no texto público.',
 '[]'::jsonb,
 '{"formato":"documento de estrutura","vinculo":"anexo da tarefa","versionado":true}'::jsonb,
 '["clareza das seções","progressão lógica","coerência com a marca","toda afirmação tem sustentação ou está marcada [CONFIRMAR]"]'::jsonb,
 'Não publica, não configura integração, não inventa preço, estoque, prazo ou prova. Dados comerciais exigem fonte datada.'),
('LP_REVISAO_ESTRUTURA', 1, 'Revisão estrutural de landing page',
 'Revisar uma estrutura de landing page e devolver problemas, critérios e sugestões concretas.',
 '["versão da estrutura a revisar","briefing","decisões aprovadas","critérios de saída"]'::jsonb,
 E'1. Ler a versão recebida e as decisões aprovadas; nada fora delas é verdade.\n2. Apontar problemas de clareza, ordem, sustentação das afirmações e coerência com a marca.\n3. Para cada problema, dar uma sugestão concreta e dizer o critério que ele fere.\n4. Não reescrever o texto inteiro: revisar.',
 '[]'::jsonb,
 '{"formato":"lista de problemas, critérios e sugestões","vinculo":"anexo da tarefa","versionado":true}'::jsonb,
 '["cada problema aponta o critério violado","cada sugestão é concreta e aplicável","nada contradiz decisão aprovada"]'::jsonb,
 'Não altera a estrutura aprovada sozinho; não decide preço, prazo ou condição comercial.')
on conflict (skill_id, versao) do nothing;

insert into public.pd_habilitacoes (personagem, skill_id, versao, ferramentas_ok, avaliacao)
values ('nami','LP_ESTRUTURA',1,true,'nao_testada'),
       ('kiro','LP_REVISAO_ESTRUTURA',1,true,'nao_testada')
on conflict (personagem, skill_id, versao) do nothing;
