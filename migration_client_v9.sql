-- =====================================================================
-- SD Workflow Design — Peça modelo / template (v9)
--
-- Guarda a "receita" de uma peça (definida pelo wizard: tipo, quantidade
-- de quadros, abordagem, tom, tipografia, fundo). Isso é o que o próximo
-- passo (produção em fornada) vai usar como base pra aplicar em cima de
-- um volume de textos/imagens vindo da planilha.
-- =====================================================================

create table if not exists piece_templates (
  id uuid primary key default gen_random_uuid(),
  client_id uuid not null references clients(id) on delete cascade,
  name text not null default 'Modelo sem nome',
  type text not null default 'carrossel',        -- carrossel | post | story (mais tipos depois)
  frame_count int not null default 5,
  approach text not null default 'institucional', -- comercial | institucional
  tone text not null default 'clara',              -- clara | escura | mista
  typography jsonb not null default '{}'::jsonb,   -- {font_family,font_size,color,line_height,letter_spacing,font_file_url}
  background jsonb not null default '{}'::jsonb,   -- {mode:'color'|'element'|'image', value, images:[...]}
  status text not null default 'rascunho',         -- rascunho | pronto
  created_by uuid references profiles(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table piece_templates enable row level security;

drop policy if exists piece_templates_select_policy on piece_templates;
drop policy if exists piece_templates_insert_policy on piece_templates;
drop policy if exists piece_templates_update_policy on piece_templates;
drop policy if exists piece_templates_delete_policy on piece_templates;

-- Ver: admin/equipe veem de todos os clientes; cliente vê os do próprio
-- (pra quando, mais pra frente, o board de aprovação precisar disso).
create policy piece_templates_select_policy on piece_templates for select
  using (
    my_role() in ('admin','team')
    or (my_role() = 'client' and client_id = my_client_id())
  );

-- Criar/editar/remover: só admin/equipe — é uma ferramenta de produção.
create policy piece_templates_insert_policy on piece_templates for insert
  with check (my_role() in ('admin','team'));

create policy piece_templates_update_policy on piece_templates for update
  using (my_role() in ('admin','team'));

create policy piece_templates_delete_policy on piece_templates for delete
  using (my_role() in ('admin','team'));
