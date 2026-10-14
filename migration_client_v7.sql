-- =====================================================================
-- SD Workflow — liga/desliga de módulos (v7)
--
-- O "SD Workflow Design" vira um app separado (design.html), com login
-- próprio mas compartilhando o mesmo Supabase/sessão. Essa tabela é a
-- "chave geral" do módulo: uma linha singleton que tanto o app.html
-- (aba Admin → Módulos) quanto o design.html leem/escrevem, pra decidir
-- se o módulo está ligado ou desligado.
-- =====================================================================

create table if not exists app_settings (
  id int primary key default 1,
  design_module_enabled boolean not null default true,
  updated_at timestamptz not null default now(),
  constraint app_settings_singleton check (id = 1)
);

insert into app_settings (id, design_module_enabled)
values (1, true)
on conflict (id) do nothing;

alter table app_settings enable row level security;

drop policy if exists app_settings_select_policy on app_settings;
drop policy if exists app_settings_update_policy on app_settings;

-- Ver: qualquer usuário logado (equipe, admin, cliente) precisa checar se
-- o módulo está ligado — inclusive pra decidir se mostra o link pra ele.
create policy app_settings_select_policy on app_settings for select
  using (auth.role() = 'authenticated');

-- Ligar/desligar: só admin.
create policy app_settings_update_policy on app_settings for update
  using (my_role() = 'admin');
