<?php
// temporário: só contagens, sem dados pessoais. Será apagado.
if(($_GET['k']??'')!=='1d5887c00c3c688352b0c643'){http_response_code(404);exit;}
header('Content-Type: text/plain; charset=utf-8');
define('WP_USE_THEMES', false);
require __DIR__ . '/wp-load.php';
global $wpdb;
$p = $wpdb->prefix;
echo "agora_wp: ".current_time('mysql')." | utc: ".gmdate('Y-m-d H:i:s')." | tz: ".wp_timezone_string()."\n";
$t = $p.'frmt_form_entry';
if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
  echo "forminator (ultimas 30h, por hora, data como gravada):\n";
  foreach ($wpdb->get_results("SELECT form_id, DATE_FORMAT(date_created,'%Y-%m-%d %H:00') h, COUNT(*) n FROM $t WHERE date_created >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 HOUR) OR date_created >= DATE_SUB(NOW(), INTERVAL 30 HOUR) GROUP BY form_id, h ORDER BY h") as $r) echo " form {$r->form_id} | {$r->h} | {$r->n}\n";
  echo "forminator total geral: ".$wpdb->get_var("SELECT COUNT(*) FROM $t")." | ultima: ".$wpdb->get_var("SELECT MAX(date_created) FROM $t")."\n";
} else echo "sem tabela forminator\n";
foreach (['wpforms_entries'] as $x) { $tt=$p.$x; if ($wpdb->get_var("SHOW TABLES LIKE '$tt'")===$tt) echo "$x: ".$wpdb->get_var("SELECT COUNT(*) FROM $tt")."\n"; }
echo "usuarios cadastrados (ultimas 30h, por hora, UTC):\n";
foreach ($wpdb->get_results("SELECT DATE_FORMAT(user_registered,'%Y-%m-%d %H:00') h, COUNT(*) n FROM {$wpdb->users} WHERE user_registered >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 HOUR) GROUP BY h ORDER BY h") as $r) echo " {$r->h} | {$r->n}\n";
echo "usuarios total: ".$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}")." | ultimo: ".$wpdb->get_var("SELECT MAX(user_registered) FROM {$wpdb->users}")."\n";
