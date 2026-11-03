-- v144 — Preferências são da PESSOA, não do navegador.
-- Layout dos gadgets, carinha da IA, fundo, tema, filtros… ficam em profiles.prefs e valem em qualquer aparelho.
-- A própria pessoa lê e grava as suas (RLS já permite update do próprio perfil). Pode rodar mais de uma vez.
alter table public.profiles add column if not exists prefs jsonb not null default '{}'::jsonb;
select count(*) as perfis, count(*) filter (where prefs <> '{}'::jsonb) as com_prefs from public.profiles;
