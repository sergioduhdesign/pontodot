-- =====================================================================
-- v163 — AULA DE DOCUMENTO: a aula nasce de um job real
--   O documento da Sumi/Muru (estratégia, copy) vira aula; a professora
--   (GPT do Sérgio) corrige pelo contrato pd-correcao/2; a aluna refaz.
--   Até 3 rodadas na noite; de manhã os responsáveis recebem a versão.
-- Pode rodar de novo sem medo.
-- =====================================================================
alter table public.pd_aulas add column if not exists tipo text not null default 'arte';          -- arte | documento
alter table public.pd_aulas add column if not exists task_id text;                                -- a tarefa real (SD-0001)
alter table public.pd_aulas add column if not exists client_id uuid references public.clients(id) on delete set null;
alter table public.pd_aulas add column if not exists doc_id uuid;                                 -- pd_docs_ia.id (v160)
alter table public.pd_aulas add column if not exists origem text;                                 -- campanhas | social | copy
alter table public.pd_aulas add column if not exists contexto jsonb not null default '{}'::jsonb; -- pacote: ficha, produtos, briefing, afinação
alter table public.pd_aulas add column if not exists prazo timestamptz;                           -- a virada de dia (manhã seguinte)
alter table public.pd_aulas add column if not exists entregue_em timestamptz;                     -- quando a última versão voltou para a equipe
alter table public.pd_aulas add column if not exists pendencias text;                             -- o que ficou em aberto depois da última rodada
alter table public.pd_aulas add column if not exists criada_por uuid references public.profiles(id) on delete set null;
alter table public.pd_aulas drop constraint if exists pd_aulas_modo_check;
alter table public.pd_aulas add constraint pd_aulas_modo_check check (modo in ('aula','incubacao','prova','teste','job'));
alter table public.pd_aulas drop constraint if exists pd_aulas_tipo_check;
alter table public.pd_aulas add constraint pd_aulas_tipo_check check (tipo in ('arte','documento'));
create index if not exists pd_aulas_tipo_idx on public.pd_aulas (tipo, status, prazo);

-- habilidades de documento (uma por tipo de trabalho real)
insert into public.pd_habilidades (skill_id, versao, nome, finalidade, entradas, metodo, criterios, limites)
values
('ESTRATEGIA_CAMPANHA', 1, 'Estratégia de campanha', 'Transformar números reais, concorrência e briefing em direção de campanha com públicos, ofertas, orçamento e copy de anúncio.',
 '["números reais da conta","pesquisa de concorrentes","ficha do cliente","foco do gestor"]'::jsonb,
 'Diagnóstico com evidência → onde a concorrência pressiona → espaço livre → públicos → ofertas e ângulos → orçamento e ritmo → próximos movimentos → copy.',
 '["toda conclusão cita o dado que a sustenta","o que não tem dado vira hipótese de teste, não afirmação","públicos descritos de forma que a Meta consiga configurar","ofertas ligadas ao produto real e ao ticket","copy com título, texto e CTA utilizáveis","nada de número inventado"]'::jsonb,
 'Orçamento final, play e qualquer promessa comercial ao cliente são decisão humana.'),
('ESTRATEGIA_SOCIAL', 1, 'Estratégia de social', 'Ler perfis reais (cliente e players) e devolver direção de conteúdo, temas, formatos, frequência e copy derivada.',
 '["dados abertos dos perfis","posts do cliente","ficha do cliente","foco do gestor"]'::jsonb,
 'Diagnóstico com número → onde cada player ganha → lacunas → temas → próximos movimentos → direção criativa → copy derivada.',
 '["frequência de postagem não vira ''melhor horário'' sem comparar desempenho","conceito defendido: onde se aplica e o que a marca aprende","temas com formato e motivo","copy pronta para a pessoa de copy avaliar","nada de alcance/idade inventado para perfil que não é nosso"]'::jsonb,
 'Publicação e resposta a cliente final são decisão humana.'),
('COPY', 1, 'Copy', 'Escrever títulos, textos e legendas a partir da estratégia e da ficha do cliente, no tom da marca.',
 '["estratégia aprovada","ficha do cliente","direção de arte quando houver"]'::jsonb,
 'Gancho → promessa com evidência → prova → chamada. Uma ideia por peça.',
 '["tom da marca respeitado","promessa sustentada pela ficha ou pelo dado","sem adjetivo vazio","CTA claro","título cabe no formato","entrevista ou fonte antes de citar fala de terceiro"]'::jsonb,
 'O texto final vai para aprovação do cliente; a decisão é humana.')
on conflict (skill_id, versao) do nothing;
