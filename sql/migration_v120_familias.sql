-- v120 — Pauta vale para qualquer família de peças (social, papelaria, apresentação, book).
alter table public.pd_pautas add column if not exists familia text not null default 'social';
alter table public.pd_pautas add column if not exists qtd int not null default 0;   -- telas/páginas desejadas (apresentação, book)
