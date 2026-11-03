-- v150 · Tumi aprende: guarda o texto original da IA pra medir "aprovado de primeira" e extrair lições do que a equipe ajusta
alter table public.pd_prospect_conversas add column if not exists original_ia text;
select 'v150 ok' as resultado;
