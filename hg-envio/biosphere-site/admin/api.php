<?php
/* BIOSPHERE · painel: edita textos/imagens/vídeos DAS PÁGINAS ORIGINAIS (sem mexer na estrutura), SEO e blog. */
@ini_set('memory_limit', '384M'); @set_time_limit(120);
$R = dirname(__DIR__); $C = $R . '/_cms';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('bsadm'); session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Strict']); session_start();
function out($a,$c=200){ http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function lj($f,$p=[]){ return is_file($f)?(json_decode(file_get_contents($f),true)?:$p):$p; }
function gj($f,$d){ $t=$f.'.tmp'; if(file_put_contents($t,json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)===false||!rename($t,$f)) out(['ok'=>false,'erro'=>'Não consegui gravar.'],500); }
function tx($s,$m){ $s=str_replace("\r",'',(string)$s); $s=preg_replace('/[^\P{C}\n\t]/u','',$s); return mb_substr(trim($s),0,$m); }
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
$multi = stripos($_SERVER['CONTENT_TYPE']??'','multipart/')===0;
$in = $multi ? $_POST : (json_decode(file_get_contents('php://input')?:'[]',true)?:[]);
$a = $_GET['a'] ?? ($in['a'] ?? '');
if($_SERVER['REQUEST_METHOD']==='POST' && ($_SERVER['HTTP_X_BS_ADMIN']??'')!=='1') out(['ok'=>false,'erro'=>'Requisição inválida.'],400);
$auth = lj($C.'/auth.php') ; $auth = is_file($C.'/auth.php') ? (include $C.'/auth.php') : [];

if($a==='login'){
  $f=$C.'/.tent.json'; $t=lj($f); $u=strtolower(trim((string)($in['usuario']??''))); $k=md5(($_SERVER['REMOTE_ADDR']??'').'bs'); $now=time();
  $t[$k]=array_values(array_filter($t[$k]??[],function($x)use($now){return $x>$now-900;}));
  if(count($t[$k])>=6) out(['ok'=>false,'erro'=>'Muitas tentativas. Aguarde 15 minutos.'],429);
  $hh=($auth['usuarios']??[])[$u]??'';
  if($hh && password_verify((string)($in['senha']??''),$hh)){ session_regenerate_id(true); $_SESSION['ok']=1; $_SESSION['u']=$u; $t[$k]=[]; @file_put_contents($f,json_encode($t)); out(['ok'=>true,'usuario'=>$u]); }
  $t[$k][]=$now; @file_put_contents($f,json_encode($t)); usleep(700000); out(['ok'=>false,'erro'=>'Usuário ou senha incorretos.'],401);
}
if($a==='sair'){ $_SESSION=[]; session_destroy(); out(['ok'=>true]); }
if($a==='sessao') out(['ok'=>!empty($_SESSION['ok']),'usuario'=>$_SESSION['u']??'']);
if(empty($_SESSION['ok'])) out(['ok'=>false,'erro'=>'Faça login.','login'=>true],401);

/* ---------- páginas ---------- */
function paginas(){ global $R; $o=[]; foreach(glob($R.'/*.html') as $f){ $n=basename($f); $s=file_get_contents($f); preg_match('#<title>(.*?)</title>#si',$s,$m); $o[]=['f'=>$n,'titulo'=>html_entity_decode($m[1]??$n,ENT_QUOTES,'UTF-8')]; } return $o; }
function pf($n){ global $R; $n=basename((string)$n); if(!preg_match('/^[a-z0-9_-]+\.html$/i',$n)||!is_file($R.'/'.$n)) out(['ok'=>false,'erro'=>'Página inválida.'],400); return $R.'/'.$n; }
/* varre só a região de conteúdo; devolve itens editáveis com posição */
function itens($s){
  $a=strpos($s,'<div id="content">'); if($a===false) $a=0; $b=strpos($s,'<div class="preloader">',$a); if($b===false) $b=strlen($s);
  $it=[]; $pos=$a; $blk='/<(h1|h2|h3|h4|p|li|blockquote|figcaption|label|span)\b([^>]*)>/i';
  while($pos<$b && preg_match('#<(img|iframe|video|source)\b[^>]*>|'.substr($blk,1,-2).'#i',$s,$m,PREG_OFFSET_CAPTURE,$pos)){
    $o=$m[0][1]; if($o>=$b) break; $tag=strtolower($m[1][0]??'');
    if($tag===''){ // bloco de texto
      preg_match($blk,$s,$mm,PREG_OFFSET_CAPTURE,$o); $t=strtolower($mm[1][0]); $ini=$o+strlen($mm[0][0]);
      $fim=stripos($s,'</'.$t.'>',$ini); if($fim===false||$fim>$b){ $pos=$ini; continue; }
      $in=substr($s,$ini,$fim-$ini);
      if(preg_match('#<(div|ul|ol|p|h[1-4]|li|img|iframe|form|section|table)\b#i',$in)){ $pos=$ini; continue; } // contêiner: entra
      if(trim(strip_tags($in))===''||($t==='li'&&stripos($in,'<i class="fa')!==false)){ $pos=$fim; continue; }
      $it[]=['tipo'=>'texto','tag'=>$t,'ini'=>$ini,'fim'=>$fim,'v'=>$in]; $pos=$fim;
    } else {
      $tagtxt=$m[0][0]; $len=strlen($tagtxt);
      if($tag==='img'||$tag==='iframe'||$tag==='source'){
        if(preg_match('/\bsrc=("|\')(.*?)\1/i',$tagtxt,$q,PREG_OFFSET_CAPTURE)){ $it[]=['tipo'=>$tag==='img'?'img':'midia','tag'=>$tag,'ini'=>$o+$q[2][1],'fim'=>$o+$q[2][1]+strlen($q[2][0]),'v'=>$q[2][0],'alt'=>($tag==='img'&&preg_match('/\balt=("|\')(.*?)\1/i',$tagtxt,$al))?$al[2]:'']; }
      } elseif($tag==='video'){ if(preg_match('/\bsrc=("|\')(.*?)\1/i',$tagtxt,$q,PREG_OFFSET_CAPTURE)) $it[]=['tipo'=>'midia','tag'=>'video','ini'=>$o+$q[2][1],'fim'=>$o+$q[2][1]+strlen($q[2][0]),'v'=>$q[2][0]]; }
      $pos=$o+$len;
    }
  }
  return $it;
}
function ctx($s,$pos){ $p=substr($s,max(0,$pos-3000),min(3000,$pos)); if(preg_match_all('#<h[1-3][^>]*>(.*?)</h[1-3]>#si',$p,$m)) return mb_substr(trim(strip_tags(end($m[1]))),0,60); return ''; }
function bkp($f){ global $C; @mkdir($C.'/bkp',0755,true); @copy($f,$C.'/bkp/'.basename($f).'.'.date('Ymd-His')); $l=glob($C.'/bkp/'.basename($f).'.*'); if(count($l)>12){ sort($l); foreach(array_slice($l,0,count($l)-12) as $x) @unlink($x); } }
function normmidia($v){
  $v=trim($v);
  if(preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/)([\w-]{6,})#',$v,$m)) return 'https://www.youtube.com/embed/'.$m[1];
  if(preg_match('#vimeo\.com/(\d+)#',$v,$m)) return 'https://player.vimeo.com/video/'.$m[1];
  if(preg_match('#drive\.google\.com/file/d/([\w-]+)#',$v,$m)) return 'https://drive.google.com/file/d/'.$m[1].'/preview';
  return $v;
}
function urlok($v){ return $v===''||preg_match('#^(https://|assets/|images/|/|\#|mailto:|tel:|[a-z0-9_./-]+\.(html|php)(\#.*)?$)#i',$v)&&stripos($v,'javascript:')===false; }

if($a==='paginas') out(['ok'=>true,'paginas'=>paginas()]);
if($a==='pagina'){ $f=pf($_GET['f']??''); $s=file_get_contents($f); $r=[]; foreach(itens($s) as $i=>$x){ $r[]=['i'=>$i,'tipo'=>$x['tipo'],'tag'=>$x['tag'],'v'=>$x['v'],'alt'=>$x['alt']??null,'ctx'=>ctx($s,$x['ini'])]; } out(['ok'=>true,'itens'=>$r]); }
if($a==='salvar_pagina'){
  $f=pf($in['f']??''); $s=file_get_contents($f); $its=itens($s); $mud=$in['mud']??[]; $n=0; $aplic=[];
  foreach($mud as $c){ $i=(int)($c['i']??-1); if(!isset($its[$i])) continue; $x=$its[$i];
    if($x['tipo']==='texto'){ if(($c['orig']??null)!==$x['v']) continue; $nv=str_replace("\r",'',(string)$c['v']); if(preg_match('#<\s*(script|iframe|object|embed|style)\b#i',$nv)||preg_match('#\bon\w+\s*=#i',$nv)||stripos($nv,'javascript:')!==false) out(['ok'=>false,'erro'=>'Texto com código não permitido.'],400); }
    else { if(($c['orig']??null)!==$x['v']) continue; $nv=$x['tipo']==='midia'?normmidia((string)$c['v']):trim((string)$c['v']); if(!urlok($nv)) out(['ok'=>false,'erro'=>'Endereço inválido: '.h($nv)],400); $nv=h($nv); $nv=str_replace('&amp;','&',$nv); }
    if($nv!==$x['v']) $aplic[$i]=$nv; }
  /* altera de trás pra frente p/ manter posições */
  krsort($aplic); foreach($aplic as $i=>$nv){ $x=$its[$i]; $s=substr($s,0,$x['ini']).$nv.substr($s,$x['fim']); $n++; }
  /* alt de imagens (depois, relê) */
  $alts=[]; foreach($mud as $c){ if(isset($c['alt'],$c['i']) ) $alts[(int)$c['i']]=(string)$c['alt']; }
  if($alts){ $its2=itens($s); krsort($alts); foreach($alts as $i=>$al){ if(!isset($its2[$i])||$its2[$i]['tipo']!=='img') continue; $x=$its2[$i]; $ini=strrpos(substr($s,0,$x['ini']),'<img'); $fim=strpos($s,'>',$x['fim']); $tag=substr($s,$ini,$fim-$ini+1); $nt=preg_match('/\balt=("|\')(.*?)\1/i',$tag)?preg_replace('/\balt=("|\')(.*?)\1/i','alt="'.h($al).'"',$tag,1):preg_replace('/<img/i','<img alt="'.h($al).'"',$tag,1); if($nt!==$tag){ $s=substr($s,0,$ini).$nt.substr($s,$fim+1); $n++; } } }
  if($n){ bkp($f); if(file_put_contents($f,$s,LOCK_EX)===false) out(['ok'=>false,'erro'=>'Não consegui gravar a página.'],500); }
  out(['ok'=>true,'alterados'=>$n]);
}

/* ---------- mídia ---------- */
function slugf($s){ $s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s); $s=strtolower(preg_replace('/[^a-z0-9]+/i','-',$s)); return trim($s,'-')?:'arquivo'; }
function lista_midia(){ global $R; $o=[]; foreach(array_merge(glob($R.'/assets/photos/*.*')?:[],glob($R.'/assets/media/*.*')?:[]) as $f){ $e=strtolower(pathinfo($f,PATHINFO_EXTENSION)); if(!in_array($e,['jpg','jpeg','png','webp','gif','svg','mp4','webm','mov'])) continue; $o[]=['p'=>str_replace($R.'/','',$f),'t'=>filemtime($f),'v'=>in_array($e,['mp4','webm','mov'])]; } usort($o,function($a,$b){return $b['t']<=>$a['t'];}); return $o; }
if($a==='midia') out(['ok'=>true,'itens'=>lista_midia()]);
if($a==='upload'){
  if(empty($_FILES['arq'])||$_FILES['arq']['error']!==0) out(['ok'=>false,'erro'=>'Arquivo não recebido (limite do servidor: '.ini_get('upload_max_filesize').').'],400);
  $nm=$_FILES['arq']['name']; $tmp=$_FILES['arq']['tmp_name']; $e=strtolower(pathinfo($nm,PATHINFO_EXTENSION)); $base=slugf(pathinfo($nm,PATHINFO_FILENAME));
  if(in_array($e,['mp4','webm','mov'])){ $mime=function_exists('mime_content_type')?mime_content_type($tmp):''; if($mime&&strpos($mime,'video/')!==0&&$mime!=='application/octet-stream') out(['ok'=>false,'erro'=>'Vídeo inválido.'],400); @mkdir($R.'/assets/media',0755,true); $d='assets/media/'.$base.'-'.substr(md5(uniqid('',true)),0,5).'.'.$e; if(!move_uploaded_file($tmp,$R.'/'.$d)) out(['ok'=>false,'erro'=>'Falha ao salvar o vídeo.'],500); out(['ok'=>true,'p'=>$d,'video'=>true]); }
  if(!in_array($e,['jpg','jpeg','png','webp','gif'])) out(['ok'=>false,'erro'=>'Use JPG, PNG, WebP ou MP4/WebM.'],400);
  $info=@getimagesize($tmp); if(!$info) out(['ok'=>false,'erro'=>'Imagem inválida.'],400);
  $max=(int)($in['max']??1800); $max=max(400,min(2600,$max)); $im=null;
  switch($info[2]){case IMAGETYPE_JPEG:$im=@imagecreatefromjpeg($tmp);break;case IMAGETYPE_PNG:$im=@imagecreatefrompng($tmp);break;case IMAGETYPE_WEBP:$im=@imagecreatefromwebp($tmp);break;case IMAGETYPE_GIF:$im=@imagecreatefromgif($tmp);break;}
  if(!$im) out(['ok'=>false,'erro'=>'Não consegui ler a imagem.'],400);
  if($info[2]===IMAGETYPE_JPEG&&function_exists('exif_read_data')){ $ex=@exif_read_data($tmp); $or=$ex['Orientation']??1; if($or==3)$im=imagerotate($im,180,0); elseif($or==6)$im=imagerotate($im,-90,0); elseif($or==8)$im=imagerotate($im,90,0); }
  $w=imagesx($im); $hh=imagesy($im); if(max($w,$hh)>$max){ $k=$max/max($w,$hh); $n=imagescale($im,(int)round($w*$k),(int)round($hh*$k)); imagedestroy($im); $im=$n; }
  $png=($info[2]===IMAGETYPE_PNG&&!empty($in['png']));
  $d='assets/photos/'.$base.'-'.substr(md5(uniqid('',true)),0,5).($png?'.png':'.jpg');
  if($png){ imagesavealpha($im,true); imagepng($im,$R.'/'.$d,6); } else { $bg=imagecreatetruecolor(imagesx($im),imagesy($im)); imagefill($bg,0,0,imagecolorallocate($bg,255,255,255)); imagecopy($bg,$im,0,0,0,0,imagesx($im),imagesy($im)); imagejpeg($bg,$R.'/'.$d,86); imagedestroy($bg); }
  imagedestroy($im); out(['ok'=>true,'p'=>$d]);
}
if($a==='importar'){ $u=trim((string)($in['url']??'')); if(preg_match('#drive\.google\.com/file/d/([\w-]+)#',$u,$m)||preg_match('#drive\.google\.com/open\?id=([\w-]+)#',$u,$m)) $u='https://drive.google.com/uc?export=download&id='.$m[1]; if(!preg_match('#^https://#',$u)) out(['ok'=>false,'erro'=>'Use um link https.'],400); $hst=parse_url($u,PHP_URL_HOST); $ip=gethostbyname($hst); if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) out(['ok'=>false,'erro'=>'Endereço não permitido.'],400); $ch=curl_init($u); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_MAXREDIRS=>3,CURLOPT_TIMEOUT=>40,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]); $d=curl_exec($ch); if(!$d||strlen($d)>25*1048576) out(['ok'=>false,'erro'=>'Não consegui baixar (ou é grande demais).'],400); $t=tempnam(sys_get_temp_dir(),'bs'); file_put_contents($t,$d); $inf=@getimagesize($t); if(!$inf){ unlink($t); out(['ok'=>false,'erro'=>'O link não é uma imagem.'],400); } $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$inf['mime']]??''; if(!$ext){ unlink($t); out(['ok'=>false,'erro'=>'Formato não aceito.'],400); } $dd='assets/photos/import-'.substr(md5($u.microtime()),0,8).'.'.$ext; rename($t,$R.'/'.$dd); chmod($R.'/'.$dd,0644); out(['ok'=>true,'p'=>$dd]); }

/* ---------- SEO ---------- */
function metaget($s,$re){ return preg_match($re,$s,$m)?html_entity_decode($m[1],ENT_QUOTES,'UTF-8'):''; }
function metaset($s,$name,$val,$attr='name'){
  $re='#<meta\s+'.$attr.'=("|\')'.preg_quote($name,'#').'\1\s+content=("|\')(.*?)\2\s*/?>#i'; $new='<meta '.$attr.'="'.$name.'" content="'.h($val).'">';
  if($val===''){ return preg_replace($re,'',$s,1); }
  if(preg_match($re,$s)) return preg_replace($re,str_replace('\\','\\\\',str_replace('$','\\$',$new)),$s,1);
  return preg_replace('#</title>#i',"</title>\n".str_replace('\\','\\\\',str_replace('$','\\$',$new)),$s,1);
}
function seo_ler($s){ return ['title'=>metaget($s,'#<title>(.*?)</title>#si'),'description'=>metaget($s,'#<meta\s+name=("|\')description\1\s+content=("|\')(.*?)\2#i')?:metaget($s,'#<meta\s+name="description"\s+content="(.*?)"#i'),'og_title'=>metaget($s,'#<meta\s+property="og:title"\s+content="(.*?)"#i'),'og_description'=>metaget($s,'#<meta\s+property="og:description"\s+content="(.*?)"#i'),'og_image'=>metaget($s,'#<meta\s+property="og:image"\s+content="(.*?)"#i'),'robots'=>metaget($s,'#<meta\s+name="robots"\s+content="(.*?)"#i')]; }
function cfg(){ global $C; return lj($C.'/config.json',[]); }
if($a==='seo_ler'){ $o=[]; foreach(paginas() as $p){ $s=file_get_contents(pf($p['f'])); $o[]=['f'=>$p['f']]+seo_ler($s); } out(['ok'=>true,'paginas'=>$o,'cfg'=>cfg()]); }
function aplicar_seo($f,$d){
  $s=file_get_contents($f); $site=cfg()['site_url']??''; $n=basename($f);
  if(isset($d['title'])&&$d['title']!=='') $s=preg_replace('#<title>.*?</title>#si','<title>'.str_replace('\\','\\\\',str_replace('$','\\$',h(tx($d['title'],120)))).'</title>',$s,1);
  $s=metaset($s,'description',tx($d['description']??'',300));
  $s=metaset($s,'og:title',tx($d['og_title']??($d['title']??''),120),'property'); $s=metaset($s,'og:description',tx($d['og_description']??($d['description']??''),300),'property');
  $s=metaset($s,'og:type','website','property'); $s=metaset($s,'og:site_name','Biosphere by C. Reymond','property');
  $img=tx($d['og_image']??'',300); if($img&&!preg_match('#^https?://#',$img)&&$site) $img=rtrim($site,'/').'/'.ltrim($img,'/'); $s=metaset($s,'og:image',$img,'property');
  $s=metaset($s,'robots',tx($d['robots']??'',40));
  if($site){ $u=rtrim($site,'/').'/'.($n==='index.html'?'':$n); $s=metaset($s,'og:url',$u,'property'); $lk='<link rel="canonical" href="'.h($u).'">'; if(preg_match('#<link rel="canonical"[^>]*>#i',$s)) $s=preg_replace('#<link rel="canonical"[^>]*>#i',$lk,$s,1); else $s=preg_replace('#</title>#i',"</title>\n".$lk,$s,1); }
  bkp($f); file_put_contents($f,$s,LOCK_EX);
}
if($a==='seo_salvar'){ $f=pf($in['f']??''); aplicar_seo($f,$in); sitemap(); out(['ok'=>true]); }
if($a==='cfg_salvar'){ $c=cfg(); $e=tx($in['email_contato']??'',120); if($e&&!filter_var($e,FILTER_VALIDATE_EMAIL)) out(['ok'=>false,'erro'=>'E-mail inválido.'],400); $u=tx($in['site_url']??'',200); if($u&&!preg_match('#^https://#',$u)) out(['ok'=>false,'erro'=>'Endereço do site deve começar com https://'],400); $c['email_contato']=$e?:($c['email_contato']??''); $c['site_url']=rtrim($u?:($c['site_url']??''),'/'); gj($C.'/config.json',$c); sitemap(); out(['ok'=>true]); }
function sitemap(){ global $R; $site=rtrim(cfg()['site_url']??'',''); if(!$site) return; $site=rtrim($site,'/'); $x='<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"; foreach(glob($R.'/*.html') as $f){ $n=basename($f); if(in_array($n,['single-post.html'])) continue; $x.='<url><loc>'.h($site.'/'.($n==='index.html'?'':$n)).'</loc><lastmod>'.date('Y-m-d',filemtime($f)).'</lastmod></url>'."\n"; } $x.='</urlset>'; file_put_contents($R.'/sitemap.xml',$x); file_put_contents($R.'/robots.txt',"User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: ".$site."/sitemap.xml\n"); }

/* ---------- blog ---------- */
function md($t){ $t=h($t); $t=preg_replace('/\*\*(.+?)\*\*/s','<strong>$1</strong>',$t); $t=preg_replace('/\*(.+?)\*/s','<em>$1</em>',$t); $t=preg_replace('/\[(.+?)\]\((https?:\/\/[^\s)]+|[a-z0-9_.\/-]+\.html)\)/i','<a href="$2">$1</a>',$t); $o=''; foreach(preg_split("/\n{2,}/",trim($t)) as $b){ $b=trim($b); if($b==='') continue; if(preg_match('/^##\s+(.*)$/s',$b,$m)) $o.="<h3>".$m[1]."</h3>\n"; else $o.='<p>'.nl2br($b,false).'</p>'."\n"; } return $o; }
function posts(){ global $C; return lj($C.'/posts.json',[]); }
function gerar_blog(){
  global $R,$C; $ps=posts(); $site=rtrim(cfg()['site_url']??'','/');
  usort($ps,function($a,$b){return strcmp($b['data'],$a['data']);});
  $tpl=file_get_contents($R.'/single-post.html');
  foreach(glob($R.'/artigo-*.html') as $f){ $n=basename($f); if($n==='artigo-01.html') continue; $slug=substr($n,7,-5); $ok=false; foreach($ps as $p) if($p['slug']===$slug&&!empty($p['pub'])) $ok=true; if(!$ok) @unlink($f); }
  $cards=''; $outros='';
  foreach($ps as $p){ if(empty($p['pub'])) continue; $arq='artigo-'.$p['slug'].'.html';
    $cards.="\t\t\t\t\t\t<div class=\"blog-post image-post\">\n\t\t\t\t\t\t\t<div class=\"inner-post\">\n".($p['img']?"\t\t\t\t\t\t\t\t<img alt=\"\" src=\"".h($p['img'])."\">\n":'')."\t\t\t\t\t\t\t\t<div class=\"post-content\">\n\t\t\t\t\t\t\t\t\t<h2><a href=\"".h($arq)."\">".h($p['titulo'])."</a></h2>\n\t\t\t\t\t\t\t\t\t<p>".h($p['resumo'])."</p>\n\t\t\t\t\t\t\t\t</div>\n".($p['cat']?"\t\t\t\t\t\t\t\t<ul class=\"post-tags\"><li><a href=\"#\"><i class=\"fa fa-tag\"></i>".h($p['cat'])."</a></li></ul>\n":'')."\t\t\t\t\t\t\t</div>\n\t\t\t\t\t\t</div>\n";
    $outros.='<li><a href="'.h($arq).'">'.h($p['titulo']).' <span>→</span></a></li>';
  }
  foreach($ps as $p){ if(empty($p['pub'])) continue; $s=$tpl; $arq='artigo-'.$p['slug'].'.html';
    $body='<div class="project-text">'."\n<h1>".h($p['titulo'])."</h1>\n".md($p['corpo'])."</div>";
    $s=preg_replace_callback('#<div class="project-text">.*?</div>#s',function($m)use($body){return $body;},$s,1);
    $sl=$p['img']?'<ul class="slides"><li><img alt="'.h($p['titulo']).'" src="'.h($p['img']).'" /></li></ul>':'<ul class="slides"></ul>';
    $s=preg_replace_callback('#<ul class="slides">.*?</ul>#s',function($m)use($sl){return $sl;},$s,1);
    $info='<li><span><i class="fa fa-user"></i></span><a href="#">'.h($p['autor']?:'Equipe Biosphere').'</a></li><li><span><i class="fa fa-tag"></i></span><a href="#">Categoria: '.h($p['cat']).'</a></li><li><span><i class="fa fa-clock-o"></i></span><a href="#">'.h($p['min']?:'3').' min de leitura</a></li>';
    $s=preg_replace_callback('#(<div class="post-info">\s*<h1>[^<]*</h1>\s*<ul>).*?(</ul>)#s',function($m)use($info){return $m[1].$info.$m[2];},$s,1);
    $s=preg_replace_callback('#(<div class="archives-sidebar">\s*<h1>[^<]*</h1>\s*<ul>).*?(</ul>)#s',function($m)use($outros){return $m[1].$outros.'<li><a href="blog.html">Ver todos os artigos <span>→</span></a></li>'.$m[2];},$s,1);
    $tt=$p['seo_title']?:($p['titulo'].' — Biosphere by C. Reymond'); $dd=$p['seo_desc']?:$p['resumo'];
    $s=preg_replace('#<title>.*?</title>#si','<title>'.str_replace('\\','\\\\',str_replace('$','\\$',h($tt))).'</title>',$s,1);
    $s=metaset($s,'description',$dd); $s=metaset($s,'og:title',$tt,'property'); $s=metaset($s,'og:description',$dd,'property'); $s=metaset($s,'og:type','article','property');
    if($site){ $u=$site.'/'.$arq; $s=metaset($s,'og:url',$u,'property'); $lk='<link rel="canonical" href="'.h($u).'">'; if(preg_match('#<link rel="canonical"[^>]*>#i',$s)) $s=preg_replace('#<link rel="canonical"[^>]*>#i',$lk,$s,1); else $s=preg_replace('#</title>#i',"</title>\n".$lk,$s,1); if($p['img']) $s=metaset($s,'og:image',$site.'/'.ltrim($p['img'],'/'),'property'); }
    $s=str_replace('</head>','<script type="application/ld+json">'.json_encode(['@context'=>'https://schema.org','@type'=>'Article','headline'=>$p['titulo'],'description'=>$dd,'datePublished'=>$p['data'],'author'=>['@type'=>'Organization','name'=>$p['autor']?:'Equipe Biosphere']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'</script></head>',$s);
    file_put_contents($R.'/'.$arq,$s);
  }
  $b=file_get_contents($R.'/blog.html');
  $blk="<!--CMS:POSTS-->\n".$cards."\t\t\t\t\t\t<!--/CMS:POSTS-->";
  if(strpos($b,'<!--CMS:POSTS-->')!==false) $b=preg_replace_callback('#<!--CMS:POSTS-->.*?<!--/CMS:POSTS-->#s',function($m)use($blk){return $blk;},$b,1);
  else $b=preg_replace_callback('#(<div class="blog-box">)#',function($m)use($blk){return $m[1]."\n".$blk;},$b,1);
  file_put_contents($R.'/blog.html',$b); sitemap();
}
if($a==='posts') out(['ok'=>true,'posts'=>posts()]);
if($a==='post_salvar'){
  $ps=posts(); $id=tx($in['id']??'',20); $t=tx($in['titulo']??'',160); if($t==='') out(['ok'=>false,'erro'=>'Informe o título.'],400);
  $slug=slugf(tx($in['slug']??'',80)?:$t); if(in_array($slug,['01'],true)) $slug.='-b';
  $p=['id'=>$id?:substr(md5(uniqid('',true)),0,10),'titulo'=>$t,'slug'=>$slug,'resumo'=>tx($in['resumo']??'',300),'cat'=>tx($in['cat']??'',40),'img'=>tx($in['img']??'',300),'corpo'=>tx($in['corpo']??'',30000),'autor'=>tx($in['autor']??'',60),'min'=>tx($in['min']??'',3),'data'=>preg_match('/^\d{4}-\d{2}-\d{2}$/',$in['data']??'')?$in['data']:date('Y-m-d'),'pub'=>!empty($in['pub']),'seo_title'=>tx($in['seo_title']??'',120),'seo_desc'=>tx($in['seo_desc']??'',300)];
  if($p['img']&&!urlok($p['img'])) out(['ok'=>false,'erro'=>'Imagem inválida.'],400);
  foreach($ps as $q) if($q['slug']===$slug&&$q['id']!==$p['id']) out(['ok'=>false,'erro'=>'Já existe outro artigo com esse endereço.'],400);
  $f=false; foreach($ps as $k=>$q) if($q['id']===$p['id']){ $ps[$k]=$p; $f=true; } if(!$f) $ps[]=$p;
  gj($C.'/posts.json',array_values($ps)); gerar_blog(); out(['ok'=>true,'id'=>$p['id'],'slug'=>$slug]);
}
if($a==='post_excluir'){ $id=tx($in['id']??'',20); $ps=array_values(array_filter(posts(),function($p)use($id){return $p['id']!==$id;})); gj($C.'/posts.json',$ps); gerar_blog(); out(['ok'=>true]); }

/* ---------- contatos / senha ---------- */
if($a==='leads') out(['ok'=>true,'itens'=>array_reverse(lj($C.'/leads.json',[]))]);
if($a==='senha'){ $u=$_SESSION['u']; $at=$in['atual']??''; $nv=(string)($in['nova']??''); if(!password_verify($at,($auth['usuarios'][$u]??''))) out(['ok'=>false,'erro'=>'Senha atual incorreta.'],400); if(strlen($nv)<10) out(['ok'=>false,'erro'=>'A nova senha precisa de 10+ caracteres.'],400); $auth['usuarios'][$u]=password_hash($nv,PASSWORD_DEFAULT); file_put_contents($C.'/auth.php','<?php'."\n".'return '.var_export($auth,true).';'."\n",LOCK_EX); out(['ok'=>true]); }
out(['ok'=>false,'erro'=>'Ação desconhecida.'],400);
