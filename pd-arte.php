<?php
// Arte pela IA da casa: monta a peça em SVG a partir do pedido do Rascunho + kit de marca + LIÇÕES APROVADAS da IA de quem pede.
require_once __DIR__ . '/pd-aula-lib.php';
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
@set_time_limit(150);
$b = sd_body(); $cid = (string)($b['client_id'] ?? '');
if (!preg_match('/^[0-9a-f-]{36}$/i', $cid)) sd_fail('Cliente inválido.');
$cli = pdAulaSup('/rest/v1/clients?id=eq.' . $cid . '&select=name')['body'][0]['name'] ?? '';
$kit = pdAulaSup('/rest/v1/brand_kits?client_id=eq.' . $cid . '&select=colors,fonts,notes')['body'][0] ?? [];
$fmt = ['post' => ['ARTE_POST', '1080x1080'], 'carrossel' => ['ARTE_CAPA_CARROSSEL', '1080x1350'], 'story' => ['ARTE_STORY', '1080x1920'], 'video' => ['ARTE_STORY', '1080x1920'], 'anuncio' => ['ARTE_POST', '1200x628']];
[$skill, $formato] = $fmt[(string)($b['tipo'] ?? 'post')] ?? ['ARTE_POST', '1080x1080'];
$marca = ['cliente' => $cli, 'paleta' => $kit['colors'] ?? [], 'fontes' => array_map(fn($f) => $f['name'] ?? '', (array)($kit['fonts'] ?? [])), 'notas' => mb_substr((string)($kit['notas'] ?? $kit['notes'] ?? ''), 0, 500)];
if (!empty($b['bg'])) $marca['cor_de_fundo_escolhida'] = (string)$b['bg']; if (!empty($b['acc'])) $marca['cor_de_destaque_escolhida'] = (string)$b['acc'];
if (!empty($b['fonte_t'])) $marca['fonte_titulos'] = (string)$b['fonte_t']; if (!empty($b['fonte_c'])) $marca['fonte_texto'] = (string)$b['fonte_c'];
$pj = pdAulaSup('/rest/v1/pd_jeito?user_id=eq.' . urlencode($u['id']) . '&select=personagem')['body'][0]['personagem'] ?? '';
$pedido = ['formato' => $formato, 'peca' => (string)($b['tipo_nome'] ?? ''), 'assunto' => mb_substr((string)($b['assunto'] ?? ''), 0, 300), 'titulo' => mb_substr((string)($b['titulo'] ?? ''), 0, 120),
    'composicao' => (string)($b['layout_nome'] ?? ''), 'pedido_original_da_pessoa' => mb_substr((string)($b['prompt'] ?? ''), 0, 1500)];
[$svg, $err] = pdArteProduzir($u['id'], $pj, $skill, $marca, $pedido);
if (!$svg) sd_fail('A IA não conseguiu montar a arte agora' . ($err ? ' (' . $err . ')' : '') . '.', 502);
echo json_encode(['svg' => $svg, 'formato' => $formato, 'licoes_aplicadas' => (bool)pdLicoesTexto($u['id'])], JSON_UNESCAPED_UNICODE);
