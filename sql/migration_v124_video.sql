-- v124 — Vídeo v1: módulo "video" na oficina (cenas + clipes + trilha + locução → MP4).
insert into public.pd_modulos (slug, nome, tipo, descricao, poder, ativo, quarentena, visivel, onde)
values ('video', 'Vídeo (render)', 'pipeline', 'Monta o MP4 das cenas do Estúdio: pranchetas com duração, clipes de vídeo, trilha e locução. Sai em Saída de materiais e no Drive.', 'executar', true, false, '{admin,team}', 'oficina')
on conflict (slug) do update set ativo = true, quarentena = false, nome = excluded.nome, descricao = excluded.descricao;
