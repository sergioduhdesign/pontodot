-- v142 — E-mail de convite configurável (painel admin → Configurações → "E-mail de convite").
-- Texto, capa, nome e site da empresa que usa a PD. Pode rodar mais de uma vez.

alter table public.app_settings add column if not exists convite_texto text;
alter table public.app_settings add column if not exists convite_capa  text;
alter table public.app_settings add column if not exists empresa_nome  text;
alter table public.app_settings add column if not exists empresa_site  text;

update public.app_settings set
  convite_texto = coalesce(convite_texto, E'Você foi convidado(a) para a PD, a plataforma de trabalho da Pontodot.ai Escola Agêntica.\n\nA {empresa} gerencia os trabalhos da equipe por aqui — por isso você recebeu este link. Para começar, falta só criar o seu acesso no link abaixo.'),
  convite_capa  = coalesce(convite_capa, 'capa-2-jardim-1086.jpg'),
  empresa_nome  = coalesce(empresa_nome, 'SD Studio Design'),
  empresa_site  = coalesce(empresa_site, 'sergioduh.com')
where id = 1;

select convite_capa, empresa_nome, empresa_site, left(convite_texto, 80) as texto from public.app_settings where id = 1;
