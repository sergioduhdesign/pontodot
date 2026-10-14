-- =====================================================================
-- SD Workflow — habilita Realtime pras tabelas principais (v12)
--
-- Mesma causa do balão de aviso (v11), só que agora pra tudo: "mudanças só
-- aparecem se eu atualizar a página" é o sintoma clássico de tabela fora da
-- publicação `supabase_realtime`. Criar uma tabela no Supabase NÃO a deixa
-- em tempo real por padrão — precisa habilitar uma por uma (toggle
-- "Realtime" no Table Editor, ou via SQL como abaixo).
--
-- Esse script confere quais dessas tabelas já estão habilitadas e só
-- adiciona as que faltam — seguro rodar quantas vezes quiser.
-- =====================================================================

do $$
declare
  t text;
begin
  foreach t in array array['tasks','posts','profiles','comments','post_comments','messages','notifications']
  loop
    if not exists (
      select 1 from pg_publication_tables
      where pubname = 'supabase_realtime' and schemaname = 'public' and tablename = t
    ) then
      execute format('alter publication supabase_realtime add table %I', t);
    end if;
  end loop;
end $$;

-- Confere o resultado — deve listar as 7 tabelas acima.
select tablename from pg_publication_tables where pubname = 'supabase_realtime' and schemaname = 'public' order by tablename;
