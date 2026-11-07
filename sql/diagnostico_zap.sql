-- Pontodot — DIAGNÓSTICO DO ZAP (só LÊ, não altera nada). Rode inteiro no editor de SQL do Supabase: devolve uma tabela com o veredito de cada ponto.
-- Cole o resultado (ou um print) na conversa.
with
fila as (select status, count(*) n, min(criado_em) mais_antiga, max(criado_em) mais_nova from public.pd_zap group by status),
fila_n as (select coalesce(sum(n) filter (where status='fila'),0) na_fila, coalesce(sum(n) filter (where status='erro'),0) com_erro, coalesce(sum(n) filter (where status='enviado'),0) enviadas, coalesce(sum(n) filter (where status='manual'),0) manuais,
                  min(mais_antiga) filter (where status='fila') fila_antiga from fila),
ult_env as (select max(enviado_em) t from public.pd_zap where status='enviado'),
ult_err as (select criado_em, tentativas, left(erro,300) erro from public.pd_zap where erro is not null order by criado_em desc limit 1),
bat as (select chave, ultima_em from public.pd_batimento),
ent as (select max(criado_em) t, count(*) filter (where criado_em > now() - interval '7 days') n7 from public.pd_avisos_log where direcao = 'entrada'),
sai as (select max(criado_em) t, count(*) filter (where status='erro' and criado_em > now() - interval '24 hours') erros24 from public.pd_avisos_log where direcao = 'saida' and canal = 'whatsapp'),
sem_zap as (select count(*) n from public.profiles where active = true and role in ('admin','team') and coalesce(btrim(zap),'') = '')
select ordem, ponto, veredito, detalhe from (
  select 1 ordem, 'Relógio da casa (cron pd-worker)' ponto,
    case when (select ultima_em from bat where chave='pd-worker') is null then '❌ NUNCA BATEU'
         when (select ultima_em from bat where chave='pd-worker') < now() - interval '15 minutes' then '❌ PARADO' else '✅ ok' end,
    coalesce('último batimento há ' || round(extract(epoch from now() - (select ultima_em from bat where chave='pd-worker'))/60) || ' min. Esperado: a cada 5 min. Se parado, é o cron do cPanel (ele manda as mensagens da fila).', 'nenhum registro: o cron do cPanel não está chamando pd-worker.php')
  union all
  select 2, 'Fila de envio (PD → pessoas)',
    case when (select com_erro from fila_n) > 0 then '❌ TEM ERRO'
         when (select na_fila from fila_n) > 0 and (select fila_antiga from fila_n) < now() - interval '20 minutes' then '⚠️ FILA PARADA'
         when (select na_fila from fila_n) > 0 then '⏳ saindo' else '✅ vazia' end,
    'na fila: ' || (select na_fila from fila_n) || ' · com erro: ' || (select com_erro from fila_n) || ' · enviadas: ' || (select enviadas from fila_n) || ' · pra mandar na mão: ' || (select manuais from fila_n)
      || coalesce(' · mais antiga na fila há ' || round(extract(epoch from now() - (select fila_antiga from fila_n))/60) || ' min', '')
  union all
  select 3, 'Último envio que deu certo',
    case when (select t from ult_env) is null then '⚠️ nunca enviou' when (select t from ult_env) < now() - interval '2 days' then '⚠️ faz mais de 2 dias' else '✅ ok' end,
    coalesce(to_char((select t from ult_env) at time zone 'America/Sao_Paulo','DD/MM HH24:MI'), 'nenhuma mensagem enviada com sucesso ainda')
  union all
  select 4, 'Último erro de envio (a causa)',
    case when (select criado_em from ult_err) is null then '✅ nenhum' when (select criado_em from ult_err) > now() - interval '2 days' then '❌ recente' else '⚠️ antigo' end,
    coalesce(to_char((select criado_em from ult_err) at time zone 'America/Sao_Paulo','DD/MM HH24:MI') || ' · tentativas ' || (select tentativas from ult_err) || ' · ' || (select erro from ult_err), '—')
  union all
  select 5, 'Chegando (Meta → PD pelo webhook)',
    case when (select t from ent) is null then '⚠️ nada recebido (ou nunca registrado)' when (select t from ent) < now() - interval '3 days' then '⚠️ faz dias' else '✅ ok' end,
    coalesce('última mensagem recebida: ' || to_char((select t from ent) at time zone 'America/Sao_Paulo','DD/MM HH24:MI') || ' · nos últimos 7 dias: ' || (select n7 from ent), 'nenhuma mensagem recebida no histórico. Se você já mandou algo pro número, o webhook da Meta não está entregando (assinatura/segredo, ou o campo "messages" desligado).')
  union all
  select 6, 'Entrada medida pelo webhook (v488)',
    case when (select ultima_em from bat where chave='zap_entrada') is null then '⚠️ ainda não bateu' else '✅ ok' end,
    coalesce('a Meta bateu no webhook há ' || round(extract(epoch from now() - (select ultima_em from bat where chave='zap_entrada'))/60) || ' min (assinatura conferiu)', 'depois de rodar este diagnóstico, mande uma mensagem pro número oficial e rode de novo: se continuar vazio, a Meta não está chegando ou a assinatura falha')
  union all
  select 7, 'Erros de envio de WhatsApp (24 h, histórico de avisos)',
    case when (select erros24 from sai) > 0 then '❌ ' || (select erros24 from sai) || ' erro(s)' else '✅ nenhum' end,
    coalesce('último envio registrado: ' || to_char((select t from sai) at time zone 'America/Sao_Paulo','DD/MM HH24:MI'), 'sem envios no histórico')
  union all
  select 8, 'Cadastro (quem não tem zap)',
    case when (select n from sem_zap) > 0 then '⚠️ ' || (select n from sem_zap) || ' pessoa(s) sem zap' else '✅ todos têm' end,
    (select coalesce(string_agg(name, ', '), 'todos os ativos da equipe têm telefone') from public.profiles where active = true and role in ('admin','team') and coalesce(btrim(zap),'') = '')
  union all
  select 9, 'Erros recentes da plataforma (Central, 48 h)',
    case when (select count(*) from public.pd_erros where quando > now() - interval '48 hours') > 0 then '⚠️ tem erro' else '✅ nenhum' end,
    coalesce((select string_agg(origem || ' ' || coalesce(onde,'') || ': ' || left(mensagem,120), E'\n') from (select * from public.pd_erros where quando > now() - interval '48 hours' order by quando desc limit 6) e), 'nada registrado (a Central só vê erros depois da v488)')
) r(ordem, ponto, veredito, detalhe) order by ordem;
