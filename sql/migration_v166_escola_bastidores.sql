-- v166 — Escola, bastidores: skills pausáveis e com origem/notas de versão. Pode rodar de novo.
alter table public.pd_habilidades add column if not exists pausada boolean not null default false;
alter table public.pd_habilidades add column if not exists origem text;          -- 'doc' | 'manual' | 'sistema'
alter table public.pd_habilidades add column if not exists notas_versao text;    -- o que mudou nesta versão
alter table public.pd_habilidades add column if not exists criada_por uuid references public.profiles(id) on delete set null;
