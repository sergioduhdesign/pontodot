-- v97 · ZAP DA EQUIPE E TOGGLE POR CLIENTE
-- Equipe: cada pessoa liga os alertas no próprio perfil (número + "ativar").
-- Cliente: o aviso só sai com o toggle "Avisar este cliente" ligado (WhatsApp e e-mail extra).
-- Seguro de rodar mais de uma vez.

alter table public.profiles add column if not exists zap       text;
alter table public.profiles add column if not exists zap_ativo boolean not null default false;

alter table public.clients  add column if not exists zap_ativo   boolean not null default false;
alter table public.clients  add column if not exists email_aviso text;
