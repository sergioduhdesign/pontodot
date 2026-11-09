-- =====================================================================
-- Pontodot v177 — SEO: quem fez (IA da casa), o que ela sugeriu e se acertou de primeira.
-- "Acertou de primeira" = a equipe aplicou o texto sem mexer (mesmo critério do termômetro de Catum).
-- Texto que a equipe corrige à mão vira LIÇÃO proposta (pd_licoes, escopo cliente) — vai para a aula.
-- Seguro rodar mais de uma vez. Precisa da v176.
-- =====================================================================
alter table public.pd_seo_propostas add column if not exists sugerido      text;      -- o que a IA propôs, intacto
alter table public.pd_seo_propostas add column if not exists editado       boolean not null default false;
alter table public.pd_seo_propostas add column if not exists ia_personagem text;
alter table public.pd_seo_propostas add column if not exists ia_segundos    int;
update public.pd_seo_propostas set sugerido = depois where sugerido is null;
-- desfazer: alter table public.pd_seo_propostas drop column sugerido, drop column editado, drop column ia_personagem, drop column ia_segundos;
