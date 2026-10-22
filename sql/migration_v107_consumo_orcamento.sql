-- v107 · Orçamento mensal e preço da IA (o "de quanto" do medidor). Só admin lê e grava.
create table if not exists public.sd_ia_config (
  id int primary key default 1 check (id = 1),
  orcamento_usd numeric not null default 0,      -- teto mensal em US$ (0 = ainda não definido)
  preco_in_usd_mi numeric not null default 3,    -- US$ por milhão de tokens de entrada
  preco_out_usd_mi numeric not null default 15,  -- US$ por milhão de tokens de saída
  cambio numeric not null default 5.5            -- R$ por US$
);
insert into public.sd_ia_config (id) values (1) on conflict (id) do nothing;
alter table public.sd_ia_config enable row level security;

create or replace function public.sd_ia_config_ler() returns jsonb
language plpgsql security definer set search_path = public as $$
begin
  if not exists (select 1 from profiles where id = auth.uid() and role = 'admin') then raise exception 'só administrador'; end if;
  return (select to_jsonb(c) from sd_ia_config c where id = 1);
end $$;

create or replace function public.sd_ia_config_salvar(p_orc numeric, p_in numeric, p_out numeric, p_cambio numeric) returns jsonb
language plpgsql security definer set search_path = public as $$
begin
  if not exists (select 1 from profiles where id = auth.uid() and role = 'admin') then raise exception 'só administrador'; end if;
  update sd_ia_config set orcamento_usd = greatest(0, coalesce(p_orc, 0)), preco_in_usd_mi = greatest(0, coalesce(p_in, 3)),
    preco_out_usd_mi = greatest(0, coalesce(p_out, 15)), cambio = greatest(0.01, coalesce(p_cambio, 5.5)) where id = 1;
  return (select to_jsonb(c) from sd_ia_config c where id = 1);
end $$;
revoke all on function public.sd_ia_config_ler() from public, anon;
revoke all on function public.sd_ia_config_salvar(numeric, numeric, numeric, numeric) from public, anon;
grant execute on function public.sd_ia_config_ler() to authenticated;
grant execute on function public.sd_ia_config_salvar(numeric, numeric, numeric, numeric) to authenticated;
