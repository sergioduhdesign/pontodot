-- v103 · Skills das IAs: o que a casa ensinou, para quem, e se já avisamos.
create table if not exists public.pd_skills (
  id          bigint generated always as identity primary key,
  slug        text unique not null,
  titulo      text not null,
  resumo      text not null,            -- o que a IA passa a fazer
  exemplo     text,                     -- como pedir
  para        text[] not null default '{}',   -- team_keys (SERGIO, JOY, LUANA, JUAN)
  ativo       boolean not null default true,
  criado_em   timestamptz not null default now(),
  anunciada_em timestamptz               -- null = ainda não avisou o time
);
alter table public.pd_skills enable row level security;
drop policy if exists pd_skills_ler on public.pd_skills;
create policy pd_skills_ler on public.pd_skills for select to authenticated using (true);

insert into public.pd_skills (slug, titulo, resumo, exemplo, para) values
 ('hooks', 'Cirurgião de hooks', 'Gera 30 hooks por mecanismos diferentes, dá nota, descarta os fracos e entrega 5 vencedores prontos (curta, agressiva, texto visual).', 'Hooks para o próximo post do Geoville', '{LUANA,SERGIO}'),
 ('calendario30', 'Calendário de 30 dias', 'Monta o mês de conteúdo com função estratégica por post (hook, formato, CTA, métrica), conforme a fase do cliente.', 'Calendário de 30 dias da MEGA', '{LUANA,JOY,SERGIO}'),
 ('diagnostico', 'Diagnóstico e reposicionamento', 'Audita posicionamento, bio, conteúdo, funil e oferta; entrega o que mudar nos próximos 7 dias.', 'Diagnóstico do Instagram da Tamo', '{JUAN,JOY,SERGIO}'),
 ('funil', 'Funil do Instagram', 'Desenha descoberta → interesse → confiança → desejo → ação, com objeções e CTAs por etapa.', 'Funil do lançamento do Refúgio Selah', '{JUAN,SERGIO}'),
 ('analise_semanal', 'Reunião semanal de métricas', 'Com os números da semana, decide o que parar, manter, dobrar e testar, e reescreve os próximos 7 dias.', 'Análise da semana da Geoville: alcance 12 mil, retenção 38%…', '{JUAN,JOY,SERGIO}')
on conflict (slug) do nothing;
