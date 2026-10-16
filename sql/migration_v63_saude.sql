-- =====================================================================
-- Pontodot v63 — PAINEL DE SAÚDE DA PLATAFORMA (v1)
-- 1) pd_batimento: cada cron grava "rodei agora" — o painel avisa se parou.
-- 2) pd_saude(): devolve num JSON só o que o painel mostra (só admin).
-- Seguro rodar mais de uma vez.
-- =====================================================================

create table if not exists public.pd_batimento (
  chave      text primary key,                 -- 'pd-worker', 'social-publish-worker', 'lembretes', 'lembrete-prazo'
  ultima_em  timestamptz not null default now(),
  detalhe    jsonb not null default '{}'::jsonb
);
alter table public.pd_batimento enable row level security;
-- ninguém de fora escreve: só o servidor (chave secreta). Admin lê pelo pd_saude().
revoke all on public.pd_batimento from anon, authenticated;

create or replace function public.pd_saude()
returns jsonb
language plpgsql
security definer
set search_path = public
as $$
declare
  eh_admin boolean;
  r jsonb;
begin
  select exists (select 1 from profiles p where p.id = auth.uid() and p.role = 'admin') into eh_admin;
  if not eh_admin then raise exception 'só admin'; end if;

  r := jsonb_build_object(
    'agora', now(),
    'batimentos', coalesce((select jsonb_agg(jsonb_build_object('chave', chave, 'ultima_em', ultima_em, 'detalhe', detalhe)
                                             order by chave) from pd_batimento), '[]'::jsonb),
    'zap', jsonb_build_object(
      'por_status', coalesce((select jsonb_object_agg(status, n) from (select status, count(*) n from pd_zap group by status) x), '{}'::jsonb),
      'fila_mais_antiga', (select min(criado_em) from pd_zap where status = 'fila'),
      'ultimo_erro', (select jsonb_build_object('quando', criado_em, 'erro', left(erro, 240))
                        from pd_zap where erro is not null order by criado_em desc limit 1)
    ),
    'publicacao', jsonb_build_object(
      'por_status', coalesce((select jsonb_object_agg(status, n) from (select status, count(*) n from scheduled_post_targets group by status) x), '{}'::jsonb),
      'vencidos_sem_publicar', (select count(*) from scheduled_post_targets t
                                  join scheduled_posts p on p.id = t.post_id
                                 where t.status = 'agendado'
                                   and coalesce(t.scheduled_at_override, p.scheduled_at) <= now() - interval '15 minutes'),
      'ultimo_erro', (select jsonb_build_object('quando', p.scheduled_at, 'erro', left(t.error_message, 240))
                        from scheduled_post_targets t join scheduled_posts p on p.id = t.post_id
                       where t.status = 'erro' and t.error_message is not null
                       order by p.scheduled_at desc limit 1)
    ),
    'lotes', jsonb_build_object(
      'robo_montando', (select count(*) from task_drive where robo is not null and robo not like 'parado:%'),
      'robo_parado',   (select count(*) from task_drive where robo like 'parado:%'),
      'entregas_a_receber', (select count(*) from task_drive where status = 'produzir')
    ),
    'meta', jsonb_build_object(
      'tokens_usuario', coalesce((select jsonb_agg(jsonb_build_object('atualizado_em', atualizado_em, 'expira_em', expira_em))
                                    from meta_user_tokens), '[]'::jsonb),
      'contas_sociais', coalesce((select jsonb_object_agg(status, n) from (select status, count(*) n from social_accounts group by status) x), '{}'::jsonb)
    ),
    'alarmes_abertos', (select count(*) from pd_alarmes where cuidado_em is null)
  );
  return r;
end $$;

revoke all on function public.pd_saude() from public, anon;
grant execute on function public.pd_saude() to authenticated;
