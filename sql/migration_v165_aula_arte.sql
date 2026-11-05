-- =====================================================================
-- v165 — AULA DE ARTE EM SEGUNDO PLANO
--   Quando o cliente aprova a copy, a PD produz o criativo em paralelo com
--   o designer humano (que segue produzindo normalmente). A arte da PD vai
--   à professora à noite e, de manhã, vira um lote de aprovação com o
--   "chapéu de aula" (approval_items.aula = true) para comparar master × PD.
--   Pré-requisitos: v163 (pd_aulas.tipo/contexto/prazo…) e v164 (aula_arte_ligada).
-- Pode rodar de novo sem medo.
-- =====================================================================
alter table public.clients add column if not exists aula_arte           boolean not null default false;  -- este cliente entra na aula de arte
alter table public.clients add column if not exists aula_arte_refs      text;                            -- pasta do Drive (url ou id) com referências de arte
alter table public.clients add column if not exists aula_arte_tipologia text;                            -- ex.: loteamento, apartamento, casa

alter table public.approval_items add column if not exists aula    boolean not null default false;      -- o "chapéu de aula": peça feita pela PD na escola
alter table public.approval_items add column if not exists aula_id uuid references public.pd_aulas(id) on delete set null;
create index if not exists approval_items_aula_idx on public.approval_items (aula_id) where aula;

-- a aula de arte de job real usa modo 'job' (já liberado na v163) e tipo 'arte'
create index if not exists pd_aulas_contexto_set_idx on public.pd_aulas ((contexto->>'origem_set_id'));
