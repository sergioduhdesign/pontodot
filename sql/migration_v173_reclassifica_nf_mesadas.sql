-- =====================================================================
-- Pontodot v173 — PD Wallet: corrige o passado segundo o Sérgio (08/11)
--  1) Quem emite NF: clientes da lista passam do bloco SEM NF para COM NF
--  2) Mesadas da Casa de meses já fechados passam a "pago"
-- Reversível: guarda o valor antigo em grupo_original / pago_original.
-- Rode primeiro a PRÉVIA (bloco 0). Só depois os blocos 1 e 2.
-- =====================================================================

-- 0) PRÉVIA — não altera nada
select descricao, mes, valor, grupo as agora, 'com_nf' as ficaria
from public.fin_lancamentos
where ambiente = 'empresa' and sentido = 'entrada' and grupo = 'sem_nf'
  and upper(descricao) ~ '(DATASTORE|IMOVELBOOKS|IMOVEL BOOKS|(^|[^A-Z])IB([^A-Z]|$)|REVISTA|SERVER SITE|(^|[^A-Z0-9])VF([^A-Z0-9]|$)|GASPAR|ATMO|(^|[^0-9])278([^0-9]|$)|GEOVILLE|(^|[^A-Z])GEO([^A-Z]|$)|AQUARELA|CONSTRUCASTRO|(^|[^A-Z])FARO([^A-Z]|$)|SIMM|DANIEL|3DS|(^|[^A-Z])LOT([^A-Z]|$)|DJANE)'
  and upper(descricao) !~ '(BRINDES|XINGA|GIRO|EMPRESTIMO|DEVOLUCAO|REPASSE)'
order by descricao, mes;

-- 1) Guarda o original e reclassifica (empresa, entradas)
alter table public.fin_lancamentos add column if not exists grupo_original text;
alter table public.fin_lancamentos add column if not exists pago_original boolean;
update public.fin_lancamentos set grupo_original = grupo where grupo_original is null;
update public.fin_lancamentos set pago_original = pago where pago_original is null;

update public.fin_lancamentos
set grupo = 'com_nf'
where ambiente = 'empresa' and sentido = 'entrada' and grupo = 'sem_nf'
  and upper(descricao) ~ '(DATASTORE|IMOVELBOOKS|IMOVEL BOOKS|(^|[^A-Z])IB([^A-Z]|$)|REVISTA|SERVER SITE|(^|[^A-Z0-9])VF([^A-Z0-9]|$)|GASPAR|ATMO|(^|[^0-9])278([^0-9]|$)|GEOVILLE|(^|[^A-Z])GEO([^A-Z]|$)|AQUARELA|CONSTRUCASTRO|(^|[^A-Z])FARO([^A-Z]|$)|SIMM|DANIEL|3DS|(^|[^A-Z])LOT([^A-Z]|$)|DJANE)'
  and upper(descricao) !~ '(BRINDES|XINGA|GIRO|EMPRESTIMO|DEVOLUCAO|REPASSE)';

-- 2) Mesadas da Casa: todo mês anterior ao atual conta como pago
update public.fin_lancamentos
set pago = true
where ambiente = 'casa' and upper(descricao) like 'MESADA%'
  and mes < date_trunc('month', now())::date and pago = false;

-- Para desfazer:
--   update public.fin_lancamentos set grupo = grupo_original where grupo_original is not null;
--   update public.fin_lancamentos set pago  = pago_original  where pago_original  is not null;
