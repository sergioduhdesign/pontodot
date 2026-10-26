-- =====================================================================
-- v111 — SALA DE AULA: habilidades de ARTE para a IA treinar
-- Pré-requisito: v85 (habilidades) e v94 (aulas/lições/notas/incubação).
-- Seguro rodar mais de uma vez.
-- =====================================================================
insert into public.pd_habilidades (skill_id, versao, nome, finalidade, entradas, metodo, criterios, limites)
values
 ('ARTE_POST', 1, 'Arte de post (feed 1080×1080)',
  'Montar uma peça de feed usando SÓ a paleta, as fontes e o tom do cliente.',
  '["marca do cliente (cores, fontes)", "assunto da peça", "composição pedida"]'::jsonb,
  '1) Ler a marca. 2) Definir hierarquia: título dominante, apoio, chamada. 3) Compor na grade com margens de 8%. 4) Aplicar cores só da paleta, destaque em UM elemento. 5) Revisar contraste e respiro.',
  '["usa apenas cores da paleta","hierarquia clara: título lê em 1 segundo","margens e respiro consistentes","um único ponto de destaque","texto cabe sem cortar","tom coerente com o segmento imobiliário"]'::jsonb,
  'Não inventa dados do empreendimento (preço, metragem). Foto = espaço reservado.'),
 ('ARTE_STORY', 1, 'Arte de story (1080×1920)',
  'Montar story vertical respeitando as áreas seguras do Instagram.',
  '["marca do cliente", "assunto", "composição"]'::jsonb,
  '1) Zonas seguras: 250px topo e 340px base livres. 2) Título grande no terço superior/central. 3) Chamada clara perto da base. 4) Paleta e fontes do cliente.',
  '["texto fora das zonas de risco","título grande e legível","uma chamada clara","paleta do cliente","composição vertical equilibrada"]'::jsonb,
  'Não inventa dados do empreendimento.'),
 ('ARTE_CAPA_CARROSSEL', 1, 'Capa de carrossel (1080×1350)',
  'Criar capa que faz parar o scroll e promete o conteúdo.',
  '["marca do cliente", "tema do carrossel"]'::jsonb,
  '1) Promessa em até 8 palavras. 2) Indicador de deslizar. 3) Contraste alto. 4) Paleta do cliente.',
  '["promessa clara em poucas palavras","indicação de que tem mais lâminas","contraste alto","paleta do cliente"]'::jsonb,
  'Não inventa dados do empreendimento.')
on conflict (skill_id, versao) do nothing;
