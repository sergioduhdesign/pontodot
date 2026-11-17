-- Identidade do cliente (nome + logo) no link de aprovação — rodar no Supabase (SQL Editor)
create or replace function public.get_marca_by_token(p_token text) returns jsonb
language sql security definer set search_path = public stable as $$
  select jsonb_build_object('nome', c.name, 'logo', c.logo_url)
  from public.approval_sets s join public.clients c on c.id = s.client_id
  where s.share_token::text = p_token and s.status <> 'rascunho' limit 1;
$$;
grant execute on function public.get_marca_by_token(text) to anon, authenticated;
