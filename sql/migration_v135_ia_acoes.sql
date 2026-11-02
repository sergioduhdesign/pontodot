-- v135 — Registro de ações da IA da casa (autonomia com desfazer)
-- Cada coisa que a IA executa no chat da plataforma entra aqui com o estado ANTES e DEPOIS.
-- "Desfazer" no chat reverte o lote inteiro, na ordem inversa.
create table if not exists public.pd_ia_acoes (
  id          bigserial primary key,
  lote        uuid not null,
  user_id     uuid,
  tipo        text not null,          -- alterar | criar | excluir | comentar | lembrar | produto | ligar
  alvo        text,                   -- id da tarefa / produto / memória
  antes       jsonb,
  depois      jsonb,
  resumo      text,
  criada_em   timestamptz not null default now(),
  desfeita_em timestamptz
);
create index if not exists pd_ia_acoes_lote on public.pd_ia_acoes (lote);
create index if not exists pd_ia_acoes_user on public.pd_ia_acoes (user_id, criada_em desc);
alter table public.pd_ia_acoes enable row level security;
-- só o servidor (chave secreta) escreve e lê; a equipe vê pelo chat
