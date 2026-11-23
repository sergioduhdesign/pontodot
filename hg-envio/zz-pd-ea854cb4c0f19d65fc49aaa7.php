<?php
if(($_GET['k']??'')!=='ea854cb4c0f19d65fc49aaa7'){http_response_code(404);exit;}
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__.'/sd-auth.php';
function g($p){ try{ $r=sd_supabase($p); return ($r['code']??0)<300 ? (array)($r['body']??[]) : null; }catch(Throwable $e){ return null; } }
function tally($rows,$col){ $o=[]; foreach((array)$rows as $x){ $k=(string)($x[$col]??'?'); $o[$k]=($o[$k]??0)+1; } ksort($o); return json_encode($o,JSON_UNESCAPED_UNICODE); }
echo "agora utc: ".gmdate('Y-m-d H:i:s')."\n\n";
$i=g('/rest/v1/pd_incubacao?select=ativa,sandbox,intervalo_horas,teto_gasto_dia,gasto_hoje,dia_do_gasto,nota_minima,teste_confirmado_em,aula_arte_ligada,provas_meta,proxima_checagem,atualizada_em');
if($i===null) $i=g('/rest/v1/pd_incubacao?select=ativa,sandbox,intervalo_horas,teto_gasto_dia,gasto_hoje,dia_do_gasto,nota_minima,proxima_checagem,atualizada_em');
echo "INCUBACAO (config da Escola): ".json_encode($i,JSON_UNESCAPED_UNICODE)."\n\n";
$a=g('/rest/v1/pd_aulas?select=status,modo,tipo,criada_em,entregue_em&order=criada_em.desc&limit=1000');
if($a===null){ echo "pd_aulas: sem leitura\n"; } else {
  echo "AULAS (ultimas ".count($a)."): por status ".tally($a,'status')." | por modo ".tally($a,'modo')." | por tipo ".tally($a,'tipo')."\n";
  $ds=array_map(fn($x)=>(string)($x['criada_em']??''),$a); sort($ds);
  echo "  primeira criada: ".($ds[0]??'-')." | ultima criada: ".end($ds)."\n";
  $e=array_filter(array_map(fn($x)=>(string)($x['entregue_em']??''),$a)); sort($e); echo "  entregues: ".count($e)." | ultima entrega: ".(end($e)?:'-')."\n";
  $por=[]; foreach($a as $x){ $d=substr((string)($x['criada_em']??''),0,10); $por[$d]=($por[$d]??0)+1; } krsort($por); echo "  criadas por dia (recentes): ".json_encode(array_slice($por,0,10,true))."\n";
}
$l=g('/rest/v1/pd_licoes?select=status,comum,criada_em,aprovada_em&order=criada_em.desc&limit=1000');
if($l===null){ echo "pd_licoes: sem leitura\n"; } else { $d=array_map(fn($x)=>(string)($x['criada_em']??''),$l); sort($d);
  echo "\nLICOES: total ".count($l)." | por status ".tally($l,'status')." | comuns ".count(array_filter($l,fn($x)=>!empty($x['comum'])))." | ultima criada ".(end($d)?:'-')."\n"; $ap=array_filter(array_map(fn($x)=>(string)($x['aprovada_em']??''),$l)); sort($ap); echo "  ultima aprovada: ".(end($ap)?:'-')."\n"; }
$f=g('/rest/v1/pd_formacoes?select=ativa,apta_em&limit=1000'); if($f!==null) echo "\nFORMACOES: ".count($f)." (ativas ".count(array_filter($f,fn($x)=>!empty($x['ativa']))).", aptas ".count(array_filter($f,fn($x)=>!empty($x['apta_em']))).")\n";
$p=g('/rest/v1/pd_ia_pedidos?select=estado,para_ia,criado_em,concluido_em&order=criado_em.desc&limit=1000');
if($p===null) echo "\npd_ia_pedidos: sem leitura\n"; else { $d=array_map(fn($x)=>(string)($x['criado_em']??''),$p); sort($d);
  echo "\nPEDIDOS ENTRE IAs: total ".count($p)." | por estado ".tally($p,'estado')." | por IA ".tally($p,'para_ia')." | primeiro ".($d[0]??'-')." | ultimo ".(end($d)?:'-')."\n"; }
$o=g('/rest/v1/pd_openai_uso?select=*&limit=200');
echo "\nUSO DA OPENAI (professor GPT por API): ".($o===null?'sem leitura':count($o)." registros").($o?(' | colunas '.implode(',',array_keys($o[0]))):'')."\n";
foreach(['pd_ia_trabalho','pd_ia_acoes','pd_jeito','pd_skills_avisos','pd_curriculo_pedidos','pd_notas','pd_observacoes'] as $t){ $r=g('/rest/v1/'.$t.'?select=*&limit=1000'); if($r===null){ echo "$t: sem leitura\n"; continue; }
  $cols=$r?array_keys($r[0]):[]; $dc=null; foreach(['criado_em','criada_em','em','created_at','atualizada_em','ultima_em'] as $c) if(in_array($c,$cols,true)){ $dc=$c; break; }
  $m=null; if($dc){ $v=array_filter(array_map(fn($x)=>(string)($x[$dc]??''),$r)); sort($v); $m=end($v); }
  echo "$t: ".count($r)." linhas".($dc?" | mais recente ($dc) $m":'')."\n"; }
