-- v108 — Lista de espera da Escola Agêntica (cadastro público da home pontodot.ai)
-- Quem se cadastra é visitante (sem conta): só consegue INSERIR. Só admin lê/gerencia.
create table if not exists public.pd_lista_espera (
  id uuid primary key default gen_random_uuid(),
  nome text not null,
  email text not null,
  whatsapp text,
  perfil text,
  motivo text,
  consentimento boolean not null default false,
  consentimento_texto text,
  origem text,
  criado_em timestamptz not null default now(),
  avisado_em timestamptz,
  cancelado_em timestamptz
);
create unique index if not exists pd_lista_espera_email_uq on public.pd_lista_espera (lower(email));
alter table public.pd_lista_espera enable row level security;

drop policy if exists "espera_inserir" on public.pd_lista_espera;
create policy "espera_inserir" on public.pd_lista_espera for insert to anon, authenticated
  with check (consentimento = true and length(btrim(nome)) between 2 and 120
              and email ~* '^[^@\s]+@[^@\s]+\.[^@\s]+$' and length(email) <= 160);

drop policy if exists "espera_admin" on public.pd_lista_espera;
create policy "espera_admin" on public.pd_lista_espera for all to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin'));

grant insert on public.pd_lista_espera to anon, authenticated;
grant select, update, delete on public.pd_lista_espera to authenticated;
