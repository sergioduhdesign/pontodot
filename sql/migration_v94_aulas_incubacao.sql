-- =====================================================================
-- Pontodot v94 — BASE DE CONHECIMENTO, AULAS, LIÇÕES, NOTAS E INCUBAÇÃO
-- O professor (GPT) ensina; o gestor valida; o que ele aprova vira lição
-- permanente da IA. A incubação roda com teto de gasto e em sandbox.
-- Seguro rodar mais de uma vez. Nada aqui apaga ou altera dado existente.
-- =====================================================================

create extension if not exists vector with schema extensions;

-- helper: é admin?
create or replace function public.pd_eh_admin() returns boolean
language sql stable security definer set search_path = public as $$
  select exists (select 1 from profiles p where p.id = auth.uid() and p.role = 'admin');
$$;
grant execute on function public.pd_eh_admin() to authenticated;

-- 1) CONHECIMENTO: o que alimenta o mundo de Mufun (busca híbrida: sentido + palavra)
create table if not exists public.pd_conhecimento (
  id          uuid primary key default gen_random_uuid(),
  escopo      text not null default 'casa' check (escopo in ('casa','cliente','habilidade','personagem')),
  cliente     text,                         -- nome do cadastro, quando escopo = cliente
  skill_id    text,                         -- quando escopo = habilidade
  personagem  text,                         -- quando escopo = personagem
  tipo        text not null check (tipo in (
                'marca','briefing','ata','entrega_aprovada','entrega_reprovada','correcao_cliente',
                'tom_de_voz','regra','processo','campanha','referencia','calendario','raciocinio','site','outro')),
  titulo      text not null,
  fonte       text,                         -- arquivo, link ou tarefa de origem
  contexto    text not null default '',     -- 1 linha: de onde vem este trecho (melhora a busca)
  texto       text not null,
  embedding   extensions.vector(384),       -- gte-small do próprio Supabase (sem chave externa)
  busca       tsvector generated always as (
                to_tsvector('portuguese', coalesce(titulo,'')||' '||coalesce(contexto,'')||' '||coalesce(texto,''))) stored,
  ativo       boolean not null default true,
  criado_por  uuid references public.profiles(id) on delete set null,
  criado_em   timestamptz not null default now()
);
create index if not exists pd_conh_busca_idx on public.pd_conhecimento using gin (busca);
create index if not exists pd_conh_emb_idx   on public.pd_conhecimento using hnsw (embedding extensions.vector_cosine_ops);
create index if not exists pd_conh_esc_idx   on public.pd_conhecimento (escopo, cliente, skill_id);

-- 2) AULA: exercício fora de tarefa real. O GPT ensina, corrige, aprova; o gestor valida.
create table if not exists public.pd_aulas (
  id           uuid primary key default gen_random_uuid(),
  ia_user_id   uuid not null references public.profiles(id) on delete cascade,  -- dono da IA aluna
  personagem   text not null,
  skill_id     text not null,
  modo         text not null default 'aula' check (modo in ('aula','incubacao')),
  status       text not null default 'aberta' check (status in (
                 'aberta','em_exercicio','em_correcao','aprovada_pelo_professor','validacao_humana',
                 'validada','reprovada','cancelada')),
  rodada       int  not null default 0,
  max_rodadas  int  not null default 5,
  exercicio    jsonb not null default '{}'::jsonb,   -- enunciado, cliente fictício, critérios
  entregas     jsonb not null default '[]'::jsonb,   -- uma por rodada
  correcoes    jsonb not null default '[]'::jsonb,   -- feedback do professor por rodada
  nota_final   numeric(5,2),
  parecer_humano text,                               -- por que o gestor aprovou/reprovou
  validada_por uuid references public.profiles(id) on delete set null,
  validada_em  timestamptz,
  criada_em    timestamptz not null default now(),
  atualizada_em timestamptz not null default now()
);
create index if not exists pd_aulas_ia_idx on public.pd_aulas(ia_user_id, status);

-- 3) LIÇÃO: só vale depois de aprovada pelo gestor. Fica ligada à IA (ou ao acervo comum).
create table if not exists public.pd_licoes (
  id          uuid primary key default gen_random_uuid(),
  ia_user_id  uuid references public.profiles(id) on delete cascade,   -- null = acervo comum
  personagem  text,
  skill_id    text not null,
  regra       text not null,
  exemplo_bom text not null default '',
  exemplo_ruim text not null default '',
  aula_id     uuid references public.pd_aulas(id) on delete set null,
  status      text not null default 'proposta' check (status in ('proposta','aprovada','recusada','aposentada')),
  comum       boolean not null default false,   -- só vira comum com aprovação explícita e sem dado de cliente
  motivo      text,
  aprovada_por uuid references public.profiles(id) on delete set null,
  aprovada_em timestamptz,
  criada_em   timestamptz not null default now()
);
create index if not exists pd_licoes_uso_idx on public.pd_licoes(ia_user_id, skill_id) where status = 'aprovada';

-- 4) NOTAS: evolução medida. Quem produz nunca é quem avalia; a humana é a régua.
create table if not exists public.pd_notas (
  id          uuid primary key default gen_random_uuid(),
  ia_user_id  uuid not null references public.profiles(id) on delete cascade,
  personagem  text not null,
  skill_id    text not null,
  fonte       text not null check (fonte in ('professor','avaliador','humano','rodadas_correcao')),
  nota        numeric(5,2) not null check (nota between 0 and 100),
  com_metodo  boolean,                     -- null = não testado; true/false = prova de causa
  aula_id     uuid references public.pd_aulas(id) on delete set null,
  criada_em   timestamptz not null default now()
);
create index if not exists pd_notas_idx on public.pd_notas(ia_user_id, skill_id, criada_em);

-- 5) INCUBAÇÃO: o relógio do gestor, com teto de gasto e sandbox
create table if not exists public.pd_incubacao (
  id               int primary key default 1 check (id = 1),     -- uma só configuração
  ativa            boolean not null default false,
  sandbox          boolean not null default true,                -- nada sai pra cliente/Meta/site/zap
  intervalo_horas  int not null default 24 check (intervalo_horas >= 1),
  teto_gasto_dia   numeric(10,2) not null default 20,            -- em USD, trava dura
  gasto_hoje       numeric(10,2) not null default 0,
  dia_do_gasto     date not null default current_date,
  nota_minima      numeric(5,2) not null default 85,             -- para liberar uma habilidade
  proxima_checagem timestamptz,
  atualizada_em    timestamptz not null default now()
);
insert into public.pd_incubacao (id) values (1) on conflict (id) do nothing;

-- 6) SEGURANÇA
alter table public.pd_conhecimento enable row level security;
alter table public.pd_aulas        enable row level security;
alter table public.pd_licoes       enable row level security;
alter table public.pd_notas        enable row level security;
alter table public.pd_incubacao    enable row level security;

drop policy if exists pd_conh_ler on public.pd_conhecimento;
create policy pd_conh_ler on public.pd_conhecimento for select to authenticated using (ativo or pd_eh_admin());
drop policy if exists pd_conh_adm on public.pd_conhecimento;
create policy pd_conh_adm on public.pd_conhecimento for all to authenticated using (pd_eh_admin()) with check (pd_eh_admin());

drop policy if exists pd_aulas_ler on public.pd_aulas;
create policy pd_aulas_ler on public.pd_aulas for select to authenticated using (ia_user_id = auth.uid() or pd_eh_admin());
drop policy if exists pd_aulas_adm on public.pd_aulas;
create policy pd_aulas_adm on public.pd_aulas for all to authenticated using (pd_eh_admin()) with check (pd_eh_admin());

drop policy if exists pd_licoes_ler on public.pd_licoes;
create policy pd_licoes_ler on public.pd_licoes for select to authenticated
  using (pd_eh_admin() or (status = 'aprovada' and (ia_user_id = auth.uid() or comum)));
drop policy if exists pd_licoes_adm on public.pd_licoes;
create policy pd_licoes_adm on public.pd_licoes for all to authenticated using (pd_eh_admin()) with check (pd_eh_admin());

drop policy if exists pd_notas_ler on public.pd_notas;
create policy pd_notas_ler on public.pd_notas for select to authenticated using (ia_user_id = auth.uid() or pd_eh_admin());
drop policy if exists pd_notas_adm on public.pd_notas;
create policy pd_notas_adm on public.pd_notas for all to authenticated using (pd_eh_admin()) with check (pd_eh_admin());

drop policy if exists pd_incub_ler on public.pd_incubacao;
create policy pd_incub_ler on public.pd_incubacao for select to authenticated using (true);
drop policy if exists pd_incub_adm on public.pd_incubacao;
create policy pd_incub_adm on public.pd_incubacao for all to authenticated using (pd_eh_admin()) with check (pd_eh_admin());

-- 7) BUSCA HÍBRIDA: sentido (vetor) + palavra (texto), fundidas por posição (RRF)
create or replace function public.pd_buscar_conhecimento(
  p_texto text, p_embedding extensions.vector(384) default null,
  p_cliente text default null, p_skill text default null, p_limite int default 8)
returns table (id uuid, titulo text, tipo text, contexto text, texto text, fonte text, pontos float)
language sql stable security definer set search_path = public, extensions as $$
  with base as (
    select c.* from pd_conhecimento c
     where c.ativo
       and (c.escopo = 'casa'
            or (c.escopo = 'cliente' and p_cliente is not null and c.cliente = p_cliente)
            or (c.escopo = 'habilidade' and p_skill is not null and c.skill_id = p_skill))
  ), por_palavra as (
    select b.id, row_number() over (order by ts_rank(b.busca, websearch_to_tsquery('portuguese', p_texto)) desc) r
      from base b where b.busca @@ websearch_to_tsquery('portuguese', p_texto) limit 30
  ), por_sentido as (
    select b.id, row_number() over (order by b.embedding <=> p_embedding) r
      from base b where p_embedding is not null and b.embedding is not null limit 30
  ), fundido as (
    select id, sum(1.0 / (60 + r)) pontos from (select * from por_palavra union all select * from por_sentido) u group by id
  )
  select b.id, b.titulo, b.tipo, b.contexto, b.texto, b.fonte, f.pontos
    from fundido f join base b on b.id = f.id
   order by f.pontos desc limit greatest(1, least(p_limite, 20));
$$;
grant execute on function public.pd_buscar_conhecimento(text, extensions.vector, text, text, int) to authenticated;

-- 8) COBERTURA: o quanto a casa já sabe, por cliente e tipo (a plataforma avisa o que está raso)
create or replace view public.pd_cobertura as
  select coalesce(cliente, '(casa)') as dono, tipo, count(*) as itens, max(criado_em) as ultimo
    from public.pd_conhecimento where ativo group by 1, 2;
grant select on public.pd_cobertura to authenticated;

-- 9) LIÇÕES QUE A IA CARREGA: aprovadas, da IA e do acervo comum, por habilidade
create or replace function public.pd_licoes_da_ia(p_user uuid, p_skill text)
returns table (regra text, exemplo_bom text, exemplo_ruim text, comum boolean)
language sql stable security definer set search_path = public as $$
  select l.regra, l.exemplo_bom, l.exemplo_ruim, l.comum
    from pd_licoes l
   where l.status = 'aprovada' and l.skill_id = p_skill and (l.ia_user_id = p_user or l.comum)
   order by l.comum, l.aprovada_em desc limit 25;
$$;
grant execute on function public.pd_licoes_da_ia(uuid, text) to authenticated;
