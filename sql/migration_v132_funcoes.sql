-- v132 · Funções da equipe (designer, social media, copy...) — dadas pelo admin no perfil
alter table public.profiles add column if not exists funcoes text[] not null default '{}';
create index if not exists profiles_funcoes_gin on public.profiles using gin (funcoes);
-- (a função "gestao_equipe" libera a pessoa a dar funções e ligar/desligar acesso dos outros)
