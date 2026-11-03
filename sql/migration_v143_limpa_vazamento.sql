-- v143 — Limpa o que vazou da conta admin pra conta de teste (vendas@sergioduh.com) na noite de 02/11:
-- a IA (pd_jeito), as conversas e a memória pessoal foram copiadas do aparelho pra conta nova.
-- Pode rodar mais de uma vez. Troque o e-mail se precisar limpar outra conta.
do $$
declare uid uuid;
begin
  select id into uid from public.profiles where lower(email) = 'vendas@sergioduh.com' limit 1;
  if uid is null then raise notice 'v143: conta não encontrada'; return; end if;
  delete from public.pd_jeito      where user_id = uid;
  delete from public.chat_threads  where user_id = uid;
  delete from public.sd_memoria    where escopo = 'pessoa' and escopo_id::text = uid::text;
  raise notice 'v143: conta % limpa (IA, conversas, memória pessoal)', uid;
end $$;
