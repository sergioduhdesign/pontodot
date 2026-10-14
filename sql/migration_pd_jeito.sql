-- =====================================================================
-- pontodot — A IA DE CADA UM
-- Rode UMA vez: Supabase > SQL Editor > New query > cola > Run.
-- Pode rodar de novo sem medo: tudo aqui é "se ainda não existe".
--
-- Uma linha por pessoa. É daqui que a IA lê como deve atuar com quem
-- está falando — o Sérgio recebe a dele, a Luana recebe a dela.
-- Antes desta tabela existir, o app tentava gravar aqui e falhava calado.
-- =====================================================================

create table if not exists public.pd_jeito (
  user_id        uuid primary key references auth.users(id) on delete cascade,
  versao         text,                                -- versão do questionário (sd-workstyle-0.1)
  personagem     text,                                -- id do cânone: nix, kiro, brum...
  apelido        text,                                -- nome que a pessoa deu; null = nome do personagem
  rebatizado_em  timestamptz,
  respostas      jsonb not null default '{}'::jsonb,  -- as 32, 1..5
  diretas        jsonb not null default '{}'::jsonb,  -- as 8 preferências escritas
  eixos          jsonb not null default '{}'::jsonb,  -- 0..100 por pilar; null quando incompleto
  confirmado_em  timestamptz,
  atualizado_em  timestamptz not null default now()
);

-- v52 — a ESCOLA: em que etapa da formação a IA está.
--   null         = ainda não matriculou
--   conhecendo   = "Conhecendo meu humano" (matriculou / respondendo)
--   formada      = formação concluída — SÓ depois da confirmação final
-- "add column if not exists": roda por cima de quem já rodou a v51.
alter table public.pd_jeito add column if not exists etapa text;
alter table public.pd_jeito add column if not exists matriculado_em timestamptz;
-- v53 — o dia em que ela nasceu pra pessoa (escolheu o personagem). A história anda a partir daqui.
alter table public.pd_jeito add column if not exists nasceu_em timestamptz;
alter table public.pd_jeito drop constraint if exists pd_jeito_etapa_ok;
alter table public.pd_jeito add constraint pd_jeito_etapa_ok
  check (etapa is null or etapa in ('conhecendo', 'formada'));

-- carimbo automático de quando mudou (o app usa pra saber qual aparelho está mais novo)
create or replace function public.pd_jeito_carimbo() returns trigger
language plpgsql as $$
begin
  new.atualizado_em := now();
  return new;
end $$;

drop trigger if exists pd_jeito_carimbo on public.pd_jeito;
create trigger pd_jeito_carimbo before update on public.pd_jeito
  for each row execute function public.pd_jeito_carimbo();

-- PRIVADO: cada um só lê e mexe na própria IA. Nem admin vê a dos outros.
alter table public.pd_jeito enable row level security;

drop policy if exists pd_jeito_ler    on public.pd_jeito;
drop policy if exists pd_jeito_criar  on public.pd_jeito;
drop policy if exists pd_jeito_mudar  on public.pd_jeito;
drop policy if exists pd_jeito_apagar on public.pd_jeito;

create policy pd_jeito_ler    on public.pd_jeito for select using (user_id = auth.uid());
create policy pd_jeito_criar  on public.pd_jeito for insert with check (user_id = auth.uid());
create policy pd_jeito_mudar  on public.pd_jeito for update using (user_id = auth.uid()) with check (user_id = auth.uid());
create policy pd_jeito_apagar on public.pd_jeito for delete using (user_id = auth.uid());

grant select, insert, update, delete on public.pd_jeito to authenticated;

-- conferência: deve devolver  pd_jeito pronta | rls = true | etapa = true
select 'pd_jeito pronta' as ok, c.relrowsecurity as rls,
       exists (select 1 from information_schema.columns
               where table_name = 'pd_jeito' and column_name = 'etapa') as etapa
  from pg_class c where c.relname = 'pd_jeito';
