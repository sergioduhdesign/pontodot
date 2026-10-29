-- v129 — trabalho É a tarefa: copys ligadas a tarefas; lote de aprovação de texto
alter table public.pd_copys add column if not exists task_id text references public.tasks(id) on delete set null;
alter table public.pd_copys add column if not exists set_id uuid;          -- lote de aprovação gerado desta copy
create index if not exists pd_copys_task on public.pd_copys (task_id);
