<?php
require_once __DIR__ . '/pd-site-lib.php';
// =====================================================================
// Pontodot — SEO DOS SITES (WordPress): auditar, sugerir com IA, aplicar com aprovação, desfazer.
//
//  auditar  lê o site como o Google lê (HTML público de cada página) + a API do WP;
//           aponta título, meta, H1, alt, canonical, noindex, schema, duplicados, robots, sitemap…
//  sugerir  a IA propõe título SEO / meta / alt com o conhecimento do cliente
//  aplicar  escreve no WP (Yoast, Rank Math, ou o plugin auxiliar do Pontodot) e guarda o "antes"
//  nada muda sem a equipe aprovar; tudo pode ser desfeito.
// =====================================================================
if (!function_exists('pdSeoAuditar')) {

if (!defined('PD_SEO_UA')) define('PD_SEO_UA', 'Mozilla/5.0 (compatible; Pontodot-SEO/1.0)');

/** GET/POST em qualquer rota da API do WP (não só /wp/v2). */
function pdSeoRest($site, $cred, $method, $rota, $body = null) {
    $base = rtrim((string)($site['url'] ?? ''), '/');
    if (!preg_match('#^https?://#', $base)) throw new Exception('O site não tem endereço configurado.');
    $ch = curl_init($base . '/wp-json' . $rota);
    $h = ['Authorization: Basic ' . base64_encode(($cred['usuario'] ?? '') . ':' . ($cred['segredo'] ?? ''))];
    $op = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_USERAGENT => PD_SEO_UA];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $op[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $op[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $op);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$raw, true)];
}

/** Baixa várias páginas públicas em paralelo. */
function pdSeoBaixar(array $urls, $conc = 6) {
    $out = []; $mh = curl_multi_init(); $fila = array_values(array_unique($urls)); $ativos = [];
    $abre = function () use (&$fila, &$ativos, $mh, $conc) {
        while ($fila && count($ativos) < $conc) {
            $u = array_shift($fila); $ch = curl_init($u);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => PD_SEO_UA, CURLOPT_ENCODING => '']);
            curl_multi_add_handle($mh, $ch); $ativos[(int)$ch] = [$ch, $u];
        }
    };
    $abre();
    do {
        curl_multi_exec($mh, $run); curl_multi_select($mh, 1.0);
        while ($i = curl_multi_info_read($mh)) {
            $ch = $i['handle']; [, $u] = $ativos[(int)$ch];
            $out[$u] = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'html' => (string)curl_multi_getcontent($ch),
                        'ms' => (int)(curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000), 'final' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
            curl_multi_remove_handle($mh, $ch); curl_close($ch); unset($ativos[(int)$ch]); $abre();
        }
    } while ($run || $ativos || $fila);
    curl_multi_close($mh);
    return $out;
}

/** Que plugin de SEO o site usa? (pelas rotas públicas da API) */
function pdSeoPlugin($site) {
    $ch = curl_init(rtrim($site['url'], '/') . '/wp-json/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => PD_SEO_UA]);
    $raw = curl_exec($ch); curl_close($ch);
    $ns = (array)(json_decode((string)$raw, true)['namespaces'] ?? []);
    if (in_array('yoast/v1', $ns, true)) return 'yoast';
    if (in_array('rankmath/v1', $ns, true)) return 'rankmath';
    if (in_array('aioseo/v1', $ns, true)) return 'aioseo';
    return 'nenhum';
}
function pdSeoChaves($plugin) {
    return $plugin === 'yoast' ? ['_yoast_wpseo_title', '_yoast_wpseo_metadesc']
         : ($plugin === 'rankmath' ? ['rank_math_title', 'rank_math_description'] : ['_pd_seo_title', '_pd_seo_desc']);
}

/** Lê o HTML de uma página como o Google. */
function pdSeoAnalisaHtml($html, $url) {
    $r = ['titulo' => '', 'meta' => '', 'robots' => '', 'canonical' => '', 'og_imagem' => '', 'h1' => [], 'h2' => 0, 'schema' => 0,
          'imgs' => 0, 'imgs_sem_alt' => [], 'links_internos' => 0, 'links_externos' => 0, 'lang' => '', 'viewport' => false];
    if ($html === '') return $r;
    libxml_use_internal_errors(true);
    $d = new DOMDocument(); $d->loadHTML('<?xml encoding="utf-8"?>' . $html); libxml_clear_errors();
    $x = new DOMXPath($d);
    $v = fn($q, $a = null) => ($n = $x->query($q)->item(0)) ? trim($a ? $n->getAttribute($a) : $n->textContent) : '';
    $r['titulo'] = $v('//title');
    $r['meta'] = $v("//meta[translate(@name,'DESCRIPTION','description')='description']", 'content');
    $r['robots'] = strtolower($v("//meta[translate(@name,'ROBTS','robts')='robots']", 'content'));
    $r['canonical'] = $v("//link[@rel='canonical']", 'href');
    $r['og_imagem'] = $v("//meta[@property='og:image']", 'content');
    $r['lang'] = $v('//html', 'lang');
    $r['viewport'] = $v("//meta[@name='viewport']", 'content') !== '';
    foreach ($x->query('//h1') as $h) $r['h1'][] = trim(preg_replace('/\s+/', ' ', $h->textContent));
    $r['h2'] = $x->query('//h2')->length;
    $r['schema'] = $x->query("//script[@type='application/ld+json']")->length;
    $host = parse_url($url, PHP_URL_HOST);
    foreach ($x->query('//img') as $im) {
        $w = (int)$im->getAttribute('width'); $h = (int)$im->getAttribute('height');
        if (($w && $w < 40) || ($h && $h < 40)) continue;            // ícones/pixels
        $r['imgs']++;
        if (trim($im->getAttribute('alt')) === '') {
            $cls = $im->getAttribute('class'); $id = preg_match('/wp-image-(\d+)/', $cls, $m) ? (int)$m[1] : 0;
            $src = $im->getAttribute('data-src') ?: $im->getAttribute('src');
            $r['imgs_sem_alt'][] = ['id' => $id, 'src' => $src];
        }
    }
    foreach ($x->query('//a[@href]') as $a) {
        $hr = trim($a->getAttribute('href'));
        if ($hr === '' || $hr[0] === '#' || preg_match('#^(mailto|tel|javascript):#i', $hr)) continue;
        $h2 = parse_url($hr, PHP_URL_HOST);
        if (!$h2 || strcasecmp(preg_replace('/^www\./', '', $h2), preg_replace('/^www\./', '', (string)$host)) === 0) $r['links_internos']++; else $r['links_externos']++;
    }
    return $r;
}

function pdSeoPeso($sev) { return $sev === 'alta' ? 18 : ($sev === 'media' ? 9 : 4); }

/** Regras por página. Devolve [[codigo, severidade, mensagem, corrigivel_com_ia]]. */
function pdSeoRegras($p, $palavras, $slug) {
    $q = [];
    $t = mb_strlen($p['titulo']); $m = mb_strlen($p['meta']);
    if ($t === 0) $q[] = ['sem_titulo', 'alta', 'Página sem título (title).', 'titulo_seo'];
    elseif ($t < 25) $q[] = ['titulo_curto', 'media', "Título SEO curto ($t caracteres; ideal 35–60).", 'titulo_seo'];
    elseif ($t > 62) $q[] = ['titulo_longo', 'media', "Título SEO longo ($t caracteres; o Google corta em ~60).", 'titulo_seo'];
    if ($m === 0) $q[] = ['sem_meta', 'alta', 'Sem meta description.', 'meta'];
    elseif ($m < 70) $q[] = ['meta_curta', 'media', "Meta description curta ($m; ideal 120–155).", 'meta'];
    elseif ($m > 165) $q[] = ['meta_longa', 'media', "Meta description longa ($m; o Google corta em ~155).", 'meta'];
    $n1 = count($p['h1']);
    if ($n1 === 0) $q[] = ['sem_h1', 'alta', 'Sem H1 na página.', null];
    elseif ($n1 > 1) $q[] = ['varios_h1', 'media', "$n1 títulos H1 (use só um).", null];
    if (strpos($p['robots'], 'noindex') !== false) $q[] = ['noindex', 'alta', 'Página marcada como noindex: o Google não indexa.', null];
    if ($p['canonical'] === '') $q[] = ['sem_canonical', 'baixa', 'Sem URL canônica.', null];
    if ($p['og_imagem'] === '') $q[] = ['sem_og', 'baixa', 'Sem imagem para compartilhar (og:image).', null];
    if ($p['schema'] === 0) $q[] = ['sem_schema', 'baixa', 'Sem dados estruturados (schema).', null];
    if (!$p['viewport']) $q[] = ['sem_viewport', 'media', 'Sem meta viewport (mobile).', null];
    if (count($p['imgs_sem_alt'])) $q[] = ['img_sem_alt', 'media', count($p['imgs_sem_alt']) . ' imagem(ns) sem texto alternativo.', 'alt'];
    if ($palavras !== null && $palavras < 300) $q[] = ['conteudo_curto', 'media', "Conteúdo curto ($palavras palavras; ideal 300+).", null];
    if ($p['links_internos'] === 0) $q[] = ['sem_links_internos', 'baixa', 'Nenhum link interno.', null];
    if ($slug && mb_strlen($slug) > 75) $q[] = ['slug_longo', 'baixa', 'Endereço (slug) muito longo.', null];
    return $q;
}

function pdSeoAuditar($clientId, $limite = 60) {
    @set_time_limit(180);
    $site = pdSiteCfg($clientId); $cred = pdCred($clientId, 'wordpress');
    if (!$site || ($site['tipo'] ?? '') !== 'wp') throw new Exception('Este cliente não está configurado como WordPress.');
    if (!$cred) throw new Exception('Sem credencial no cofre (serviço: wordpress).');
    $base = rtrim($site['url'], '/');
    $eu = pdSeoRest($site, $cred, 'GET', '/wp/v2/users/me?context=edit');
    if ($eu['code'] !== 200) throw new Exception(pdWPErro($eu));
    $papeis = (array)($eu['body']['roles'] ?? []);
    $plugin = pdSeoPlugin($site);

    // o que existe no site (posts + páginas publicados)
    $paginas = [['tipo' => 'home', 'wp_id' => 0, 'url' => $base . '/', 'slug' => '', 'palavras' => null, 'titulo_wp' => 'Página inicial', 'modificado' => null]];
    foreach (['pages' => 'page', 'posts' => 'post'] as $rota => $tipo) {
        for ($pg = 1; $pg <= 3; $pg++) {
            $r = pdWP($site, $cred, 'GET', "/$rota?per_page=50&page=$pg&_fields=id,link,slug,title,content,modified");
            if ($r['code'] !== 200 || !is_array($r['body']) || !$r['body']) break;
            foreach ($r['body'] as $it) {
                $paginas[] = ['tipo' => $tipo, 'wp_id' => (int)$it['id'], 'url' => $it['link'], 'slug' => $it['slug'],
                    'palavras' => str_word_count(strip_tags((string)($it['content']['rendered'] ?? '')), 0, 'àáâãçéêíóôõúÀÁÂÃÇÉÊÍÓÔÕÚ'),
                    'titulo_wp' => html_entity_decode(strip_tags((string)($it['title']['rendered'] ?? '')), ENT_QUOTES, 'UTF-8'), 'modificado' => $it['modified'] ?? null];
            }
            if (count($r['body']) < 50) break;
        }
    }
    $total = count($paginas); $paginas = array_slice($paginas, 0, max(1, (int)$limite));

    // checagens do site
    $extras = pdSeoBaixar([$base . '/robots.txt', $base . '/sitemap_index.xml', $base . '/wp-sitemap.xml', $base . '/sitemap.xml'], 4);
    $site_q = [];
    if (strpos($base, 'https://') !== 0) $site_q[] = ['sem_https', 'alta', 'O site não usa HTTPS.'];
    $rb = $extras[$base . '/robots.txt'] ?? null;
    if (!$rb || $rb['code'] !== 200) $site_q[] = ['sem_robots', 'media', 'Não há robots.txt.'];
    else {
        if (preg_match('/^\s*Disallow:\s*\/\s*$/mi', $rb['html']) && preg_match('/User-agent:\s*\*/i', $rb['html'])) $site_q[] = ['robots_bloqueia', 'alta', 'O robots.txt bloqueia o site inteiro (Disallow: /).'];
        if (!preg_match('/^\s*Sitemap:/mi', $rb['html'])) $site_q[] = ['robots_sem_sitemap', 'baixa', 'O robots.txt não aponta o sitemap.'];
    }
    $temSitemap = false; foreach (['/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml'] as $s) if (($extras[$base . $s]['code'] ?? 0) === 200 && strpos($extras[$base . $s]['html'], '<') !== false) $temSitemap = true;
    if (!$temSitemap) $site_q[] = ['sem_sitemap', 'alta', 'Nenhum sitemap encontrado.'];
    if ($plugin === 'nenhum') $site_q[] = ['sem_plugin_seo', 'media', 'Nenhum plugin de SEO (Yoast/Rank Math) detectado: o plugin auxiliar do Pontodot cobre título e meta.'];
    if ($plugin === 'aioseo') $site_q[] = ['aioseo', 'media', 'All in One SEO detectado: a auditoria funciona, mas a gravação automática ainda não.'];
    if (!in_array('administrator', $papeis, true)) $site_q[] = ['papel', 'media', 'O usuário da PD no WordPress não é Administrador: algumas correções podem ser recusadas.'];

    // lê cada página como o Google
    $htmls = pdSeoBaixar(array_column($paginas, 'url'), 6);
    $itens = []; $titulos = []; $metas = [];
    foreach ($paginas as $p) {
        $h = $htmls[$p['url']] ?? ['code' => 0, 'html' => '', 'ms' => 0];
        $a = $h['code'] === 200 ? pdSeoAnalisaHtml($h['html'], $p['url']) : pdSeoAnalisaHtml('', $p['url']);
        $q = $h['code'] === 200 ? pdSeoRegras($a, $p['palavras'], $p['slug']) : [['fora', 'alta', 'A página respondeu HTTP ' . $h['code'] . '.', null]];
        if ($h['ms'] > 1800) $q[] = ['lenta', 'media', 'Resposta lenta (' . $h['ms'] . ' ms até o primeiro byte).', null];
        $itens[] = $p + ['analise' => $a, 'ms' => $h['ms'], 'problemas' => $q];
        if ($a['titulo']) $titulos[mb_strtolower($a['titulo'])][] = count($itens) - 1;
        if ($a['meta']) $metas[mb_strtolower($a['meta'])][] = count($itens) - 1;
    }
    foreach ($titulos as $ix) if (count($ix) > 1) foreach ($ix as $i) $itens[$i]['problemas'][] = ['titulo_repetido', 'alta', 'Mesmo título SEO em ' . count($ix) . ' páginas.', 'titulo_seo'];
    foreach ($metas as $ix) if (count($ix) > 1) foreach ($ix as $i) $itens[$i]['problemas'][] = ['meta_repetida', 'media', 'Mesma meta description em ' . count($ix) . ' páginas.', 'meta'];

    $soma = 0;
    foreach ($itens as &$it) {
        $nota = 100; foreach ($it['problemas'] as $pr) $nota -= pdSeoPeso($pr[1]);
        $it['nota'] = max(0, $nota); $soma += $it['nota'];
        unset($it['analise']['imgs_sem_alt'][50]);
    } unset($it);
    usort($itens, fn($a, $b) => $a['nota'] <=> $b['nota']);
    $notaSite = $itens ? (int)round($soma / count($itens)) : 0;
    foreach ($site_q as $s) $notaSite -= (int)(pdSeoPeso($s[1]) / 2);
    $notaSite = max(0, $notaSite);
    $cont = ['alta' => 0, 'media' => 0, 'baixa' => 0];
    foreach ($itens as $it) foreach ($it['problemas'] as $pr) $cont[$pr[1]]++;
    $resumo = ['nota' => $notaSite, 'plugin' => $plugin, 'papeis' => $papeis, 'paginas_no_site' => $total, 'paginas_lidas' => count($itens),
               'problemas' => $cont, 'site' => $site_q, 'sitemap' => $temSitemap];
    sd_supabase('/rest/v1/pd_seo_auditorias', 'POST', ['client_id' => $clientId, 'nota' => $notaSite, 'plugin' => $plugin, 'resumo' => $resumo, 'itens' => $itens]);
    return ['resumo' => $resumo, 'itens' => $itens];
}

// ------------------------------------------------------------------ quem faz o SEO na casa
/** A IA da casa responsável pelo SEO (área "seo" → Sumi por padrão) e quantas vezes acertou de primeira. */
function pdSeoQuem() {
    $q = pdQuemFazArea('seo'); $cat = pdPersonagens(); $p = $q['personagem'];
    $r = sd_supabase('/rest/v1/pd_seo_propostas?status=in.(aplicada,revertida,recusada)&select=status,editado&limit=5000')['body'] ?? [];
    $total = 0; $acertos = 0;
    foreach ((array)$r as $x) { $total++; if (($x['status'] ?? '') === 'aplicada' && empty($x['editado'])) $acertos++; }
    return ['personagem' => $p, 'nome' => $q['nome'], 'titulo' => $q['titulo'], 'user_id' => $q['id'],
            'sobre' => $cat[$p][4] ?? '', 'avatar' => '/icone-ia.php?p=' . $p . '&s=88', 'acertos' => $acertos, 'total' => $total];
}
/** Lições aprovadas (da casa e deste cliente) que a IA precisa seguir ao escrever SEO. */
function pdSeoLicoes($clientId) {
    $r = sd_supabase('/rest/v1/pd_licoes?status=eq.aprovada&skill_id=eq.SEO_TEXTOS&or=(comum.eq.true,cliente_ref.eq.' . urlencode($clientId) . ')&select=regra,exemplo_bom,exemplo_ruim&order=criada_em.desc&limit=15');
    $t = [];
    foreach ((array)($r['body'] ?? []) as $l) $t[] = '- ' . $l['regra'] . (!empty($l['exemplo_bom']) ? ' (bom: "' . mb_substr($l['exemplo_bom'], 0, 140) . '")' : '') . (!empty($l['exemplo_ruim']) ? ' (evitar: "' . mb_substr($l['exemplo_ruim'], 0, 140) . '")' : '');
    return implode("\n", $t);
}

// ------------------------------------------------------------------ IA
function pdSeoIA($system, $user, $max = 1500) {
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) throw new Exception('Sem chave da IA.');
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    foreach ([defined('PD_SEO_MODEL') ? PD_SEO_MODEL : 'claude-sonnet-4-5', 'claude-haiku-4-5'] as $modelo) {
        $ch = curl_init($base . '/v1/messages');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 70,
            CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['model' => $modelo, 'max_tokens' => $max,
                'system' => $system . (function_exists('pdBlindagem') ? pdBlindagem() : ''), 'messages' => [['role' => 'user', 'content' => $user]]])]);
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) continue;
        $t = ''; foreach ((array)(json_decode((string)$raw, true)['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $t .= $c['text'];
        if (preg_match('/\{.*\}/s', $t, $m)) $t = $m[0];
        $j = json_decode($t, true);
        if (is_array($j)) return $j;
    }
    throw new Exception('A IA não respondeu agora.');
}
function pdSeoCorta($s, $max) {
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    if (mb_strlen($s) <= $max) return $s;
    $c = mb_substr($s, 0, $max + 1); $esp = mb_strrpos($c, ' ');
    return rtrim(mb_substr($c, 0, $esp && $esp > $max * 0.6 ? $esp : $max), " ,;:-–—");
}

/** Cria propostas (antes → depois) para UMA página. Não escreve no WP. */
function pdSeoSugerir($clientId, $tipo, $wpId, $url, $usuarioId = null) {
    @set_time_limit(120);
    $site = pdSiteCfg($clientId);
    if (!$site) throw new Exception('Site não configurado.');
    $pg = pdSeoBaixar([$url], 1)[$url] ?? null;
    if (!$pg || $pg['code'] !== 200) throw new Exception('Não consegui abrir a página (' . ($pg['code'] ?? 0) . ').');
    $a = pdSeoAnalisaHtml($pg['html'], $url);
    $d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML('<?xml encoding="utf-8"?>' . $pg['html']); libxml_clear_errors();
    foreach (['script', 'style', 'nav', 'footer', 'header', 'noscript'] as $tg) { foreach (iterator_to_array($d->getElementsByTagName($tg)) as $n) $n->parentNode->removeChild($n); }
    $txt = trim(preg_replace('/\s+/', ' ', $d->textContent));
    $cli = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($clientId) . '&select=name')['body'][0]['name'] ?? '';
    $sem = array_slice(array_filter($a['imgs_sem_alt'], fn($i) => $i['id'] > 0), 0, 12);
    $q = pdSeoQuem(); $t0 = microtime(true); $lic = pdSeoLicoes($clientId);
    $sys = 'Você é ' . $q['nome'] . ', ' . mb_strtolower($q['titulo']) . ' da casa Pontodot (SD Agência), e hoje cuida do SEO. ' . $q['sobre'] . ' '
         . 'Você é um especialista sênior em SEO para o mercado imobiliário brasileiro. Reescreva o SEO de UMA página existente, em pt-BR. '
         . 'Regras: título SEO de 40 a 60 caracteres, com o assunto principal no começo e a marca no fim quando couber; meta description de 130 a 155 caracteres, com benefício concreto e chamada para ação, sem aspas; '
         . 'texto alternativo de imagem descritivo, até 110 caracteres, sem "imagem de". NUNCA invente dados do empreendimento (preço, metragem, prazo, endereço, número de unidades) — use só o que está no conteúdo da página ou no conhecimento do cliente. '
         . 'Não use caixa alta corrida nem promessas enganosas. Responda SOMENTE JSON: {"titulo_seo":"...","meta":"...","alts":[{"id":123,"alt":"..."}],"motivo":"uma frase explicando o foco escolhido"}. '
         . 'Se o título ou a meta atual já estiver ótimo, devolva-o igual. Só inclua em "alts" os ids recebidos.'
         . ($lic !== '' ? "\n\nLIÇÕES JÁ APROVADAS PELA EQUIPE (siga sempre):\n" . $lic : '');
    $msg = json_encode(['cliente' => $cli, 'url' => $url, 'titulo_atual' => $a['titulo'], 'meta_atual' => $a['meta'], 'h1' => $a['h1'],
        'conteudo_da_pagina' => mb_substr($txt, 0, 3500), 'imagens_sem_alt' => array_map(fn($i) => ['id' => $i['id'], 'arquivo' => basename(parse_url($i['src'], PHP_URL_PATH) ?? '')], $sem),
        'conhecimento_do_cliente' => function_exists('pdConhecimentoCliente') ? pdConhecimentoCliente($clientId, 'seo', 5000) : ''], JSON_UNESCAPED_UNICODE);
    $j = pdSeoIA($sys, $msg, 1500);
    $props = [];
    $seg = (int)round(microtime(true) - $t0);
    $novo = function ($campo, $alvo, $antes, $depois) use (&$props, $clientId, $tipo, $wpId, $url, $usuarioId, $j, $q, $seg) {
        $depois = trim((string)$depois); if ($depois === '' || $depois === trim((string)$antes)) return;
        $props[] = ['client_id' => $clientId, 'tipo' => $tipo, 'wp_id' => $wpId, 'url' => $url, 'campo' => $campo, 'alvo' => $alvo,
                    'antes' => (string)$antes, 'depois' => $depois, 'sugerido' => $depois, 'motivo' => (string)($j['motivo'] ?? ''), 'criado_por' => $usuarioId,
                    'ia_personagem' => $q['personagem'], 'ia_segundos' => $seg];
    };
    $novo('titulo_seo', null, $a['titulo'], pdSeoCorta($j['titulo_seo'] ?? '', 62));
    $novo('meta', null, $a['meta'], pdSeoCorta($j['meta'] ?? '', 158));
    $idsOk = array_column($sem, 'id');
    foreach ((array)($j['alts'] ?? []) as $al) if (in_array((int)($al['id'] ?? 0), $idsOk, true)) $novo('alt', (string)(int)$al['id'], '', pdSeoCorta($al['alt'] ?? '', 125));
    if (!$props) return [];
    $r = sd_supabase('/rest/v1/pd_seo_propostas', 'POST', $props, ['Prefer: return=representation']);
    return (array)($r['body'] ?? []);
}

// ------------------------------------------------------------------ aplicar / desfazer
function pdSeoEscrever($site, $cred, $plugin, $p, $valor) {
    $rota = $p['tipo'] === 'page' ? 'pages' : 'posts';
    if ($p['campo'] === 'alt') {
        $r = pdWP($site, $cred, 'POST', '/media/' . (int)$p['alvo'], ['alt_text' => $valor]);
        if (!in_array($r['code'], [200, 201], true)) throw new Exception(pdWPErro($r));
        return;
    }
    if ((int)$p['wp_id'] === 0) throw new Exception('A página inicial usa o título/meta do tema ou da página "Início"; aplique pelo plugin de SEO.');
    $chaves = pdSeoChaves($plugin); $chave = $p['campo'] === 'titulo_seo' ? $chaves[0] : $chaves[1];
    $le = pdWP($site, $cred, 'GET', "/$rota/" . (int)$p['wp_id'] . '?context=edit&_fields=id,meta');
    if ($le['code'] !== 200) throw new Exception(pdWPErro($le));
    $meta = (array)($le['body']['meta'] ?? []);
    if (!array_key_exists($chave, $meta)) throw new Exception('PLUGIN_AUXILIAR: o campo "' . $chave . '" não está liberado na API. Instale o plugin auxiliar do Pontodot (mu-plugin) neste site.');
    $r = pdWP($site, $cred, 'POST', "/$rota/" . (int)$p['wp_id'], ['meta' => [$chave => $valor]]);
    if (!in_array($r['code'], [200, 201], true)) throw new Exception(pdWPErro($r));
    if (($r['body']['meta'][$chave] ?? null) !== $valor) throw new Exception('O WordPress aceitou, mas não gravou o campo. Confira o plugin auxiliar.');
}

function pdSeoAplicar($clientId, array $ids, array $edicoes = []) {
    $site = pdSiteCfg($clientId); $cred = pdCred($clientId, 'wordpress');
    if (!$site || !$cred) throw new Exception('Site ou cofre sem configuração.');
    $plugin = pdSeoPlugin($site); $res = [];
    foreach ($ids as $id) {
        $id = preg_replace('/[^0-9a-fA-F-]/', '', (string)$id);
        $p = sd_supabase('/rest/v1/pd_seo_propostas?id=eq.' . urlencode($id) . '&client_id=eq.' . urlencode($clientId) . '&select=*')['body'][0] ?? null;
        if (!$p || !in_array($p['status'], ['proposta', 'erro', 'revertida'], true)) { $res[] = ['id' => $id, 'ok' => false, 'erro' => 'proposta indisponível']; continue; }
        $valor = isset($edicoes[$id]) ? trim((string)$edicoes[$id]) : $p['depois'];
        try {
            pdSeoEscrever($site, $cred, $plugin, $p, $valor);
            $editado = trim((string)($p['sugerido'] ?? $p['depois'])) !== trim($valor);
            sd_supabase('/rest/v1/pd_seo_propostas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'aplicada', 'depois' => $valor, 'editado' => $editado, 'aplicado_em' => gmdate('c'), 'erro' => null]);
            if ($editado) pdSeoLicaoDaEdicao($clientId, $p, $valor);
            $res[] = ['id' => $id, 'ok' => true];
        } catch (Exception $e) {
            sd_supabase('/rest/v1/pd_seo_propostas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'erro', 'erro' => mb_substr($e->getMessage(), 0, 300)]);
            $res[] = ['id' => $id, 'ok' => false, 'erro' => $e->getMessage()];
        }
    }
    return $res;
}
/** A equipe corrigiu à mão o texto da IA: vira uma lição PROPOSTA (nada vale até a equipe aprovar na aula). */
function pdSeoLicaoDaEdicao($clientId, $p, $valor) {
    try {
        $campo = ['titulo_seo' => 'título SEO', 'meta' => 'meta description', 'alt' => 'texto alternativo'][$p['campo']] ?? $p['campo'];
        sd_supabase('/rest/v1/pd_licoes', 'POST', [
            'ia_user_id' => pdQuemFazArea('seo')['id'] ?? null, 'personagem' => $p['ia_personagem'] ?? null, 'skill_id' => 'SEO_TEXTOS', 'escopo' => 'cliente', 'cliente_ref' => $clientId,
            'regra' => 'A equipe corrigiu um ' . $campo . ' escrito pela IA: prefira o jeito da versão corrigida (tom, tamanho e escolha de palavras).',
            'exemplo_ruim' => mb_substr((string)($p['sugerido'] ?? ''), 0, 400), 'exemplo_bom' => mb_substr($valor, 0, 400), 'status' => 'proposta', 'comum' => false]);
    } catch (Throwable $e) { /* a lição é bônus: nunca trava a aplicação */ }
}
function pdSeoReverter($clientId, $id) {
    $site = pdSiteCfg($clientId); $cred = pdCred($clientId, 'wordpress');
    $id = preg_replace('/[^0-9a-fA-F-]/', '', (string)$id);
    $p = sd_supabase('/rest/v1/pd_seo_propostas?id=eq.' . urlencode($id) . '&client_id=eq.' . urlencode($clientId) . '&select=*')['body'][0] ?? null;
    if (!$p || $p['status'] !== 'aplicada') throw new Exception('Só dá para desfazer o que foi aplicado.');
    pdSeoEscrever($site, $cred, pdSeoPlugin($site), $p, (string)$p['antes']);
    sd_supabase('/rest/v1/pd_seo_propostas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'revertida']);
}

/** A página REAL do cliente, já com o título/meta/alts propostos aplicados (só para olhar; nada é gravado). */
function pdSeoPrevia($url, $titulo, $meta, array $alts) {
    $pg = pdSeoBaixar([$url], 1)[$url] ?? null;
    if (!$pg || $pg['code'] !== 200) throw new Exception('Não consegui abrir a página (' . ($pg['code'] ?? 0) . ').');
    libxml_use_internal_errors(true);
    $d = new DOMDocument(); $d->loadHTML('<?xml encoding="utf-8"?>' . $pg['html']); libxml_clear_errors();
    $x = new DOMXPath($d);
    foreach (iterator_to_array($x->query('//script|//noscript|//iframe')) as $n) $n->parentNode->removeChild($n);
    foreach ($x->query('//*[@*[starts-with(name(),"on")]]') as $n) foreach (iterator_to_array($n->attributes) as $a) if (stripos($a->name, 'on') === 0) $n->removeAttribute($a->name);
    $head = $d->getElementsByTagName('head')->item(0);
    if ($head) {
        $b = $d->createElement('base'); $b->setAttribute('href', $url); $head->insertBefore($b, $head->firstChild);
        $cs = $d->createElement('meta'); $cs->setAttribute('charset', 'utf-8'); $head->insertBefore($cs, $head->firstChild);
        if ($titulo !== '') { $t = $d->getElementsByTagName('title')->item(0); if (!$t) { $t = $d->createElement('title'); $head->appendChild($t); } $t->nodeValue = ''; $t->appendChild($d->createTextNode($titulo)); }
        if ($meta !== '') {
            $m = $x->query("//meta[@name='description']")->item(0);
            if (!$m) { $m = $d->createElement('meta'); $m->setAttribute('name', 'description'); $head->appendChild($m); }
            $m->setAttribute('content', $meta);
        }
        $st = $d->createElement('style'); $st->appendChild($d->createTextNode('.pd-alt-novo{outline:3px solid #22c55e!important;outline-offset:-3px}'));
        $head->appendChild($st);
    }
    foreach ($x->query('//img') as $im) {
        $ds = $im->getAttribute('data-src'); if ($ds !== '') $im->setAttribute('src', $ds);
        $dss = $im->getAttribute('data-srcset'); if ($dss !== '') $im->setAttribute('srcset', $dss);
        $im->removeAttribute('loading');
        if (preg_match('/wp-image-(\d+)/', $im->getAttribute('class'), $mm) && isset($alts[$mm[1]]) && trim($alts[$mm[1]]) !== '') {
            $im->setAttribute('alt', $alts[$mm[1]]); $im->setAttribute('class', $im->getAttribute('class') . ' pd-alt-novo');
        }
    }
    return preg_replace('/<\?xml[^>]*\?>\s*/', '', $d->saveHTML());
}

/** Plugin auxiliar (mu-plugin): libera os campos de SEO na API e, sem Yoast/Rank Math, publica título e meta. */
function pdSeoPluginFonte() {
    return <<<'PHP'
<?php
/**
 * Plugin Name: Pontodot SEO
 * Description: Libera os campos de SEO (título e meta description) para a API do WordPress, para a PD ajustar com aprovação. Sem Yoast/Rank Math, ele mesmo publica o título e a meta.
 * Version: 1.0
 * Instalação: envie este arquivo para wp-content/mu-plugins/pontodot-seo.php (crie a pasta se não existir).
 */
if (!defined('ABSPATH')) exit;
add_action('init', function () {
    $chaves = ['_yoast_wpseo_title', '_yoast_wpseo_metadesc', 'rank_math_title', 'rank_math_description', '_pd_seo_title', '_pd_seo_desc'];
    foreach (['post', 'page'] as $t) {
        foreach ($chaves as $k) {
            register_post_meta($t, $k, [
                'show_in_rest' => true, 'single' => true, 'type' => 'string',
                'auth_callback' => function ($allowed, $meta_key, $post_id) { return current_user_can('edit_post', $post_id); },
            ]);
        }
    }
});
function pd_seo_outro_plugin() { return defined('WPSEO_VERSION') || class_exists('RankMath') || defined('AIOSEO_VERSION'); }
add_filter('pre_get_document_title', function ($t) {
    if (pd_seo_outro_plugin() || !is_singular()) return $t;
    $v = get_post_meta(get_the_ID(), '_pd_seo_title', true);
    return $v !== '' ? $v : $t;
}, 99);
add_action('wp_head', function () {
    if (pd_seo_outro_plugin() || !is_singular()) return;
    $v = get_post_meta(get_the_ID(), '_pd_seo_desc', true);
    if ($v !== '') echo '<meta name="description" content="' . esc_attr($v) . '">' . "\n";
}, 1);
PHP;
}

}
