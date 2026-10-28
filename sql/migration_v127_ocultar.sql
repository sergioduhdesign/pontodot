-- v127 — tirar da lista curta da home (continua na lista completa)
alter table public.pd_trabalhos add column if not exists oculto boolean not null default false;
alter table public.pd_pecas add column if not exists oculta boolean not null default false;
