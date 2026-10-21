-- v101 — APRENDIZADO DA CASA: material (link, texto, arquivo) que a equipe manda pra IA aprender,
--         e o ESPELHO com o cadastro de clientes: arquivou/apagou o cliente, o conhecimento dele sai junto.
-- Rode INTEIRO no Supabase → SQL Editor → Run. Esperado: "Success". Seguro rodar de novo.

create table if not exists public.sd_aprendizado (
  id          uuid primary key default gen_random_uuid(),
  titulo      text not null,
  escopo      text not null default 'agencia' check (escopo in ('agencia','cliente')),
  client_id   uuid,                         -- quando escopo = cliente
  tipo        text,                         -- link | arquivo | texto | pesquisa
  fonte       text,                         -- URL ou nome do arquivo
  resumo      text,                         -- o essencial, nas palavras da IA
  texto       text,                         -- o material inteiro (quando deu pra ler)
  midia_path  text,                         -- arquivo guardado no bucket zap-midia (pdf/imagem)
  autor_id    uuid,
  origem      text,                         -- chat | zap
  ativo       boolean not null default true,
  suspenso_cliente boolean not null default false,   -- true = desligado porque o cliente foi arquivado
  created_at  timestamptz not null default now()
);
create index if not exists sd_aprendizado_cli_idx on public.sd_aprendizado (client_id) where ativo;
alter table public.sd_aprendizado enable row level security;
drop policy if exists sd_aprendizado_equipe on public.sd_aprendizado;
create policy sd_aprendizado_equipe on public.sd_aprendizado for all to authenticated
  using (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')))
  with check (exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team')));

alter table public.sd_memoria add column if not exists suspenso_cliente boolean not null default false;

-- ESPELHO: cliente arquivado → conhecimento dele desliga; reativado → volta; apagado → some.
create or replace function public.sd_espelho_cliente() returns trigger
language plpgsql security definer set search_path = public as $$
begin
  if tg_op = 'DELETE' then
    delete from public.sd_memoria     where escopo = 'cliente' and escopo_id = old.id;
    delete from public.sd_aprendizado where client_id = old.id;
    delete from public.client_knowledge where cliente_nome = old.name;
    update public.reunioes_texto set cliente = null, client_id = null, como_achou = 'nao_achou' where client_id = old.id;
    return old;
  end if;
  if tg_op = 'UPDATE' and old.active is distinct from new.active then
    if coalesce(new.active, true) = false then
      update public.sd_memoria     set ativo = false, suspenso_cliente = true where escopo = 'cliente' and escopo_id = new.id and ativo;
      update public.sd_aprendizado set ativo = false, suspenso_cliente = true where client_id = new.id and ativo;
    else
      update public.sd_memoria     set ativo = true, suspenso_cliente = false where escopo = 'cliente' and escopo_id = new.id and suspenso_cliente;
      update public.sd_aprendizado set ativo = true, suspenso_cliente = false where client_id = new.id and suspenso_cliente;
    end if;
  end if;
  return new;
end $$;
drop trigger if exists sd_espelho_cliente_del on public.clients;
create trigger sd_espelho_cliente_del before delete on public.clients for each row execute function public.sd_espelho_cliente();
drop trigger if exists sd_espelho_cliente_upd on public.clients;
create trigger sd_espelho_cliente_upd after update of active on public.clients for each row execute function public.sd_espelho_cliente();
