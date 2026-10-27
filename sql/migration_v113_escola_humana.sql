-- v113 — Escola para o humano: cursos, permissões por pessoa, pasta de repositório por IA, instruções da pessoa.
-- Idempotente. Precisa de v94, v111, v112. Não mexe em nada de produção.

-- 1) PERMISSÕES (tabela à parte, sem política: só o servidor lê/escreve — ninguém se promove pelo próprio perfil)
create table if not exists public.pd_escola_perm (
  user_id    uuid primary key references public.profiles(id) on delete cascade,
  matricular boolean not null default false,   -- pode matricular a própria IA em cursos
  acompanhar boolean not null default false,   -- pode ver evolução, amostras, receita e marcos da própria IA
  criar      boolean not null default false,   -- pode criar/abrir cursos e aulas
  atualizada_em timestamptz not null default now()
);
alter table public.pd_escola_perm enable row level security;

-- 2) CURSOS CRIADOS NA PLATAFORMA (os 16 do universo vêm de pd/escola-cursos.json)
create table if not exists public.pd_cursos (
  slug        text primary key,
  nome        text not null,
  descricao   text not null default '',
  icone       text not null default 'estrela',
  responsavel text not null default '',
  responsavel_user uuid references public.profiles(id) on delete set null,
  vagas       int,
  exercicio   text not null default '',
  criterios   jsonb not null default '[]'::jsonb,   -- [{criterio, peso}] somando 100
  guia        jsonb not null default '{}'::jsonb,   -- exemplo bom/ruim, transferência, pedido fictício
  skill_ids   text[] not null default '{}',
  status      text not null default 'rascunho' check (status in ('rascunho','aberto','arquivado')),
  criado_por  uuid references public.profiles(id) on delete set null,
  criada_em   timestamptz not null default now()
);
alter table public.pd_cursos enable row level security;
drop policy if exists pd_cursos_ler on public.pd_cursos;
create policy pd_cursos_ler on public.pd_cursos for select to authenticated using (status = 'aberto' or pd_eh_admin());

-- 3) PASTA DE REPOSITÓRIO (Drive) de cada formação
alter table public.pd_formacoes add column if not exists pasta_id  text;
alter table public.pd_formacoes add column if not exists pasta_url text;

-- 4) INSTRUÇÕES DA PESSOA para a IA dela (texto livre, vira dado citado, nunca ordem de sistema)
alter table public.pd_jeito add column if not exists instrucao_extra text;
