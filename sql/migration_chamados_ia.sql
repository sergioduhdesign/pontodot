-- SD Workflow — CHAMADOS PRA IA (e-mail) + PAUSAR/RETOMAR
-- Pode rodar quantas vezes quiser.
alter table task_drive add column if not exists status_antes      text;
alter table task_drive add column if not exists ultimo_chamado    text;
alter table task_drive add column if not exists ultimo_chamado_em timestamptz;

alter table task_drive drop constraint if exists task_drive_status_check;
alter table task_drive add constraint task_drive_status_check
  check (status in ('aguardando','produzir','entregue','revisao','corrigir','liberado','pausado'));
