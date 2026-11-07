-- Pontodot v150 — CENTRAL DE ERROS (o que quebrou, onde, quando). Só o admin lê; o servidor grava com a chave secreta.
create table if not exists public.pd_erros (
  id        bigserial primary key,
  quando    timestamptz not null default now(),
  origem    text not null,            -- php | js | webhook | teste
  onde      text,                     -- arquivo/endpoint ou página
  mensagem  text not null,
  detalhe   jsonb,
  quem      text                      -- e-mail ou id de quem estava logado (se houver)
);
create index if not exists pd_erros_quando_idx on public.pd_erros (quando desc);
alter table public.pd_erros enable row level security;
drop policy if exists pd_erros_admin on public.pd_erros;
create policy pd_erros_admin on public.pd_erros for select to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));
-- limpeza: erros com mais de 30 dias saem sozinhos (chamada pelo painel de saúde)
create or replace function public.pd_erros_limpa() returns void language sql security definer as
$$ delete from public.pd_erros where quando < now() - interval '30 days'; $$;
