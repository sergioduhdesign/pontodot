-- v121 — Derivações: o texto pode vir de um link (Google Doc / arquivo no Drive); textos longos viram documento no Drive sozinhos.
alter table public.pd_pautas add column if not exists texto_link text;
