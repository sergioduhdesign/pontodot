-- v194 · Catum com as ferramentas de escritório (chat, mesas, recados, agenda)
-- Equipe (admin/team) usa tudo. Conta de cliente só conversa no canal do próprio cliente.

-- 1) chat permanente: geral, por lugar, avisos, do cliente e direto entre duas pessoas
create table if not exists public.catum_chat (
  id        bigserial primary key,
  canal     text not null,                 -- 'geral' | 'avisos' | 'lugar:<cena>' | 'cli:<client_id>' | 'dm:<uid menor>:<uid maior>'
  de        uuid not null default auth.uid(),
  nome      text not null,
  texto     text not null check (char_length(texto) between 1 and 500),
  criado_em timestamptz not null default now()
);
create index if not exists catum_chat_canal on public.catum_chat (canal, id desc);
alter table public.catum_chat enable row level security;
create or replace function public.catum_canal_meu(p_canal text) returns boolean language sql stable security definer set search_path = public as $$
  select case
    when p_canal in ('geral', 'avisos') or p_canal like 'lugar:%' then is_staff()
    when p_canal like 'cli:%' then is_staff() or (my_role() = 'client' and p_canal = 'cli:' || my_client_id()::text)
    when p_canal like 'dm:%' then position(auth.uid()::text in p_canal) > 0
    else false end
$$;
drop policy if exists catum_chat_ler on public.catum_chat;
create policy catum_chat_ler on public.catum_chat for select to authenticated using (catum_canal_meu(canal));
drop policy if exists catum_chat_por on public.catum_chat;
create policy catum_chat_por on public.catum_chat for insert to authenticated
  with check (de = auth.uid() and catum_canal_meu(canal) and (canal <> 'avisos' or my_role() = 'admin'));
grant select, insert on public.catum_chat to authenticated;
grant usage, select on sequence public.catum_chat_id_seq to authenticated;
do $$ begin alter publication supabase_realtime add table public.catum_chat; exception when others then null; end $$;

-- 2) mesa de cada pessoa da equipe (na ala Pontodot) e recados deixados na mesa
create table if not exists public.catum_mesa (
  user_id uuid primary key default auth.uid(),
  nome text not null, x integer not null, y integer not null,
  atualizado_em timestamptz not null default now()
);
alter table public.catum_mesa enable row level security;
drop policy if exists catum_mesa_ler on public.catum_mesa;
create policy catum_mesa_ler on public.catum_mesa for select to authenticated using (is_staff());
drop policy if exists catum_mesa_minha on public.catum_mesa;
create policy catum_mesa_minha on public.catum_mesa for all to authenticated using (is_staff() and user_id = auth.uid()) with check (is_staff() and user_id = auth.uid());
grant select, insert, update, delete on public.catum_mesa to authenticated;

create table if not exists public.catum_recado (
  id bigserial primary key,
  para uuid not null, de uuid not null default auth.uid(), nome text not null,
  texto text not null check (char_length(texto) between 1 and 400),
  lido boolean not null default false, criado_em timestamptz not null default now()
);
create index if not exists catum_recado_para on public.catum_recado (para, lido, id desc);
alter table public.catum_recado enable row level security;
drop policy if exists catum_recado_ler on public.catum_recado;
create policy catum_recado_ler on public.catum_recado for select to authenticated using (para = auth.uid() or de = auth.uid());
drop policy if exists catum_recado_por on public.catum_recado;
create policy catum_recado_por on public.catum_recado for insert to authenticated with check (is_staff() and de = auth.uid());
drop policy if exists catum_recado_lido on public.catum_recado;
create policy catum_recado_lido on public.catum_recado for update to authenticated using (para = auth.uid()) with check (para = auth.uid());
grant select, insert, update on public.catum_recado to authenticated;
grant usage, select on sequence public.catum_recado_id_seq to authenticated;

-- 3) agenda de encontros em Catum
create table if not exists public.catum_reuniao (
  id bigserial primary key,
  titulo text not null check (char_length(titulo) between 1 and 120),
  quando timestamptz not null, cena text not null default 'escritorio', link text,
  criado_por uuid not null default auth.uid(), nome text not null, criado_em timestamptz not null default now()
);
create index if not exists catum_reuniao_quando on public.catum_reuniao (quando);
alter table public.catum_reuniao enable row level security;
drop policy if exists catum_reuniao_ler on public.catum_reuniao;
create policy catum_reuniao_ler on public.catum_reuniao for select to authenticated using (is_staff());
drop policy if exists catum_reuniao_por on public.catum_reuniao;
create policy catum_reuniao_por on public.catum_reuniao for insert to authenticated with check (is_staff() and criado_por = auth.uid());
drop policy if exists catum_reuniao_tira on public.catum_reuniao;
create policy catum_reuniao_tira on public.catum_reuniao for delete to authenticated using (criado_por = auth.uid() or my_role() = 'admin');
grant select, insert, delete on public.catum_reuniao to authenticated;
grant usage, select on sequence public.catum_reuniao_id_seq to authenticated;
