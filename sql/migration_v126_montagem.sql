-- v126 — Montagem da derivação: site/rede social do cliente e se tem design system
alter table public.pd_pautas add column if not exists site_link text;
alter table public.pd_pautas add column if not exists design_system boolean;   -- null = não disse; true = tem (kit); false = não tem (IA extrai da principal e do site)
