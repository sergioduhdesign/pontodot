-- =====================================================================
-- SD Workflow — habilita Realtime pra tabela `notifications` (v11)
--
-- Motivo do balão de aviso não aparecer: criar uma tabela nova no Supabase
-- NÃO a coloca automaticamente na publicação de Realtime (`supabase_realtime`)
-- — isso é por tabela, precisa habilitar explicitamente (ou marcar o toggle
-- "Realtime" dela no Table Editor). Sem isso, o INSERT acontece no banco
-- normalmente, mas o navegador de quem está online nunca fica sabendo — daí
-- o balão nunca aparecia, só o push (que não depende de realtime).
--
-- Roda isso DEPOIS do v10. Seguro rodar mais de uma vez (verifica antes de
-- adicionar, senão o Postgres reclama de "already member of publication").
-- =====================================================================

do $$
begin
  if not exists (
    select 1 from pg_publication_tables
    where pubname = 'supabase_realtime' and schemaname = 'public' and tablename = 'notifications'
  ) then
    alter publication supabase_realtime add table notifications;
  end if;
end $$;
