-- =====================================================================
-- v112 — FORMAÇÃO das IAs, com papéis separados
--   PD ........ produz o exercício e a versão da aluna; reconhece a correção
--   dot ....... só TRANSPORTA (aviso "[SD-IA] AULA · CORRIGIR" → Projeto do ChatGPT → CORRECAO.json)
--   professor . o GPT dentro do Projeto do ChatGPT do Sérgio
--   Sérgio .... decisão final; só depois disso uma lição entra no repertório
-- Pré-requisitos: v94 e v111. Seguro rodar mais de uma vez (idempotente).
-- A rotina automática continua DESLIGADA até a rodada de teste ponta a ponta ser confirmada.
-- =====================================================================

-- ---- configuração
alter table public.pd_incubacao add column if not exists boas_para_apto int not null default 3;
alter table public.pd_incubacao add column if not exists provas_meta    int not null default 30;
alter table public.pd_incubacao add column if not exists chamados_dia   int not null default 5;      -- ~5 passagens/dia pela fila (avisos ao dot)
alter table public.pd_incubacao add column if not exists professor_projeto text not null default 'Aprendendo a fazer arte de post';
alter table public.pd_incubacao add column if not exists professor_modo text not null default 'projeto_gpt';
-- modos: 'projeto_gpt' (GPT do Projeto, dot só transporta) | 'api' (PILOTO explícito: GPT cru por chave, sem a bagagem do Sérgio)
update public.pd_incubacao set professor_modo = 'projeto_gpt' where professor_modo not in ('projeto_gpt', 'api');
alter table public.pd_incubacao drop constraint if exists pd_incubacao_professor_modo_check;
alter table public.pd_incubacao add constraint pd_incubacao_professor_modo_check check (professor_modo in ('projeto_gpt', 'api'));

-- ---- teste ponta a ponta (trava da rotina automática)
alter table public.pd_incubacao add column if not exists teste_aula_id          uuid;
alter table public.pd_incubacao add column if not exists teste_reconhecido_em   timestamptz;  -- a PD leu um parecer válido do professor
alter table public.pd_incubacao add column if not exists teste_confirmado_em    timestamptz;  -- o Sérgio confirmou que a ponte funcionou
-- segurança: nada de rotina ligada sem o teste confirmado
update public.pd_incubacao set ativa = false where id = 1 and teste_confirmado_em is null;

-- ---- aulas
alter table public.pd_aulas add column if not exists dia date;
alter table public.pd_aulas add column if not exists chamado jsonb;   -- aviso ao dot: chamado, pasta do Drive, rodada, envios, erro
alter table public.pd_aulas drop constraint if exists pd_aulas_modo_check;
alter table public.pd_aulas add constraint pd_aulas_modo_check check (modo in ('aula','incubacao','prova','teste'));
create index if not exists pd_aulas_dia_idx on public.pd_aulas(ia_user_id, skill_id, dia);

-- ---- lições: escopo (lição de cliente nunca vira regra geral)
alter table public.pd_licoes add column if not exists escopo      text not null default 'habilidade';
alter table public.pd_licoes add column if not exists cliente_ref text;
alter table public.pd_licoes drop constraint if exists pd_licoes_escopo_check;
alter table public.pd_licoes add constraint pd_licoes_escopo_check check (escopo in ('habilidade','universal','cliente'));
-- lição de cliente não pode ser comum
update public.pd_licoes set comum = false where escopo = 'cliente' and comum = true;
alter table public.pd_licoes drop constraint if exists pd_licoes_cliente_nao_comum;
alter table public.pd_licoes add constraint pd_licoes_cliente_nao_comum check (not (escopo = 'cliente' and comum = true));

-- ---- formações (matrícula de cada IA numa habilidade)
create table if not exists public.pd_formacoes (
  id          uuid primary key default gen_random_uuid(),
  ia_user_id  uuid not null references public.profiles(id) on delete cascade,
  personagem  text not null default 'ia',
  skill_id    text not null,
  ativa       boolean not null default true,
  meta_provas int,
  apta_em     timestamptz,
  criada_em   timestamptz not null default now(),
  unique (ia_user_id, skill_id)
);
alter table public.pd_formacoes enable row level security;
drop policy if exists pd_form_ler on public.pd_formacoes;
create policy pd_form_ler on public.pd_formacoes for select to authenticated using (ia_user_id = auth.uid() or pd_eh_admin());
drop policy if exists pd_form_adm on public.pd_formacoes;
create policy pd_form_adm on public.pd_formacoes for all to authenticated using (pd_eh_admin()) with check (pd_eh_admin());
