-- v122 — Derivações: referências por link (sites, Behance, pastas do Drive…) que a IA olha antes de derivar.
alter table public.pd_pautas add column if not exists refs jsonb not null default '[]'::jsonb;
