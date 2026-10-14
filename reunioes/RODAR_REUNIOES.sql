-- =====================================================================
-- SD — Orquestração de reuniões: tabela de controle e memória
-- Rode INTEIRO no Supabase → SQL Editor → Run. Esperado: "Success".
-- Não mexe em nada existente.
-- =====================================================================

create table if not exists reunioes_controle (
  file_id       text primary key,          -- id do doc do Read.ai no Drive
  titulo        text not null,
  cliente       text,                      -- nome do cliente/projeto identificado
  reuniao_em    date,                      -- data da reunião
  resumo        text,                      -- 2-3 linhas (vira memória pesquisável)
  ata_doc_id    text,                      -- Google Doc da ata gerada
  ata_url       text,
  task_id       text,                      -- tarefa inicial criada pra Joy (SD-xxxx)
  status        text not null default 'ok',  -- ok | falhou
  erro          text,
  processado_em timestamptz not null default now()
);

create index if not exists reunioes_controle_cliente_idx on reunioes_controle (cliente);
create index if not exists reunioes_controle_data_idx    on reunioes_controle (reuniao_em desc);

alter table reunioes_controle enable row level security;

-- equipe logada pode LER (pra pesquisa no app); só o servidor escreve
drop policy if exists reunioes_ler on reunioes_controle;
create policy reunioes_ler on reunioes_controle
  for select to authenticated using (true);
