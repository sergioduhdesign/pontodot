-- v148 · cada cliente fala pelo próprio e-mail (entrada IMAP + saída SMTP), configurado dentro da plataforma
create table if not exists public.pd_vendas_caixas (
  id uuid primary key default gen_random_uuid(),
  client_id uuid not null unique references public.clients(id) on delete cascade,
  email text not null,
  usuario text not null,
  senha_cifrada text,                       -- AES-256-GCM; só o servidor abre
  imap_host text not null, imap_porta int not null default 993,
  smtp_host text not null, smtp_porta int not null default 465,
  ativo boolean not null default true,
  ultimo_uid bigint not null default 0,
  ultimo_erro text, ultima_leitura timestamptz,
  atualizado_em timestamptz default now(),
  created_at timestamptz default now()
);
alter table public.pd_vendas_caixas enable row level security;   -- sem policy: nem o navegador enxerga; só o servidor (service role)
select 'v148 ok' as resultado;
