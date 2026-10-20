-- Pontodot v98d — mensagens RECEBIDAS por WhatsApp entram no histórico, na conversa de quem mandou
alter table public.pd_avisos_log add column if not exists direcao text not null default 'saida';
