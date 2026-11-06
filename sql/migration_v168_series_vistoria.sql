-- v168 — Séries semanais de tarefa (vistoria de social) + planilha de gestão
-- Rode uma vez no SQL Editor do Supabase.
create table if not exists public.pd_series (
  id             uuid primary key default gen_random_uuid(),
  tipo           text not null default 'vistoria_social',
  client_id      text,            -- cliente da PD (quando já cadastrado)
  cliente_nome   text,            -- como veio no pedido (casa com o bloco da planilha)
  product_id     text,
  produto_nome   text,
  resp           text,            -- chave de equipe (ex.: CAMILA)
  titulo         text not null,
  obs            text,
  dow            int  not null default 5,        -- dia em que a tarefa nasce (5 = sexta)
  prazo_dias     int  not null default 6,        -- prazo: 6 dias depois (quinta seguinte)
  inicio         date,
  em_espera      boolean not null default false,
  motivo_espera  text,
  ativa          boolean not null default true,
  ultima_semana  date,            -- início (sexta) da última semana já gerada
  ultima_task    text,
  ultimo_lembrete date,
  criado_por     uuid,
  created_at     timestamptz not null default now()
);
create index if not exists pd_series_ativa on public.pd_series (ativa, em_espera);
alter table public.pd_series enable row level security;   -- só o servidor (chave secreta) lê e grava

create table if not exists public.pd_series_config (
  id            int primary key default 1,
  planilha_id   text,
  planilha_url  text,
  atualizado_em timestamptz default now(),
  constraint pd_series_config_um check (id = 1)
);
alter table public.pd_series_config enable row level security;
