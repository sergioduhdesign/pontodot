-- v151 · Tumi automático (liga/desliga por cliente) + o que ele oferece
create table if not exists public.pd_vendas_auto (
  client_id uuid primary key references public.clients(id) on delete cascade,
  ativo boolean not null default false,
  oferta text not null default '',            -- produtos de agência que o Tumi pode oferecer (nunca o PD)
  consulta text not null default '',          -- o que ele procura sozinho todo dia (ex.: incorporadoras com lançamento em Campinas)
  descobrir_dia int not null default 10,
  enviar_dia int not null default 20,
  nota_min int not null default 0,
  hora_ini int not null default 9, hora_fim int not null default 18,
  ultima_descoberta text,
  atualizado_em timestamptz default now()
);
alter table public.pd_vendas_auto enable row level security;   -- só o servidor enxerga
select 'v151 ok' as resultado;
