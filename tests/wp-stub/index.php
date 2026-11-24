<?php
// Mini-WordPress pour tester le plugin en local : php -S localhost:8765 -t tests/wp-stub
// Page :  /?url=…&parcours=…&gpx=…&coureur=…      AJAX : /admin-ajax.php?action=…
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
$GLOBALS['store'] = __DIR__ . '/.store';
@mkdir($GLOBALS['store']);
$GLOBALS['hooks'] = [];
$GLOBALS['shortcodes'] = [];
$GLOBALS['assets'] = ['css' => [], 'js' => [], 'reg' => [], 'loc' => ''];

function add_action($h, $cb) { $GLOBALS['hooks'][$h][] = $cb; }
function add_filter($h, $cb) { $GLOBALS['hooks'][$h][] = $cb; }
function do_action($h) { foreach ($GLOBALS['hooks'][$h] ?? [] as $cb) call_user_func($cb); }
function add_shortcode($t, $cb) { $GLOBALS['shortcodes'][$t] = $cb; }
function shortcode_atts($d, $a) { return array_merge($d, array_intersect_key((array) $a, $d)); }
function wp_next_scheduled() { return true; }
function wp_schedule_event() {}
function wp_clear_scheduled_hook() {}
function register_deactivation_hook() {}
function plugin_dir_url() { return '/plugin/'; }
function admin_url($p) { return '/' . $p; }
function wp_register_style($h, $src) { $GLOBALS['assets']['rcss'][$h] = $src; }
function wp_register_script($h, $src) { $GLOBALS['assets']['rjs'][$h] = $src; }
function wp_enqueue_style($h) { $GLOBALS['assets']['css'][] = $GLOBALS['assets']['rcss']['leaflet']; $GLOBALS['assets']['css'][] = $GLOBALS['assets']['rcss'][$h]; }
function wp_enqueue_script($h) { $GLOBALS['assets']['js'][] = $GLOBALS['assets']['rjs']['leaflet']; $GLOBALS['assets']['js'][] = $GLOBALS['assets']['rjs'][$h]; }
function wp_localize_script($h, $n, $v) { $GLOBALS['assets']['loc'] = "var $n = " . json_encode($v) . ';'; }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url_raw($v) { return filter_var($v, FILTER_SANITIZE_URL); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function current_user_can() { return true; }
function wp_json_encode($v) { return json_encode($v); }
function wp_http_validate_url($u) { return preg_match('#^https?://#', $u) ? $u : false; }
function nocache_headers() { header('Cache-Control: no-store'); }
function wp_send_json($d, $s = 200) { http_response_code($s); header('Content-Type: application/json'); echo json_encode($d); exit; }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error { private $m; function __construct($c, $m = '') { $this->m = $m; } function get_error_message() { return $this->m; } }
function _f($k) { return $GLOBALS['store'] . '/' . preg_replace('/[^a-z0-9_]/i', '_', $k); }
function get_option($k, $d = false) { return is_file(_f("o_$k")) ? unserialize(file_get_contents(_f("o_$k"))) : $d; }
function update_option($k, $v) { file_put_contents(_f("o_$k"), serialize($v)); return true; }
function delete_option($k) { @unlink(_f("o_$k")); }
function get_transient($k) { $f = _f("t_$k"); if (!is_file($f)) return false; [$exp, $v] = unserialize(file_get_contents($f)); return $exp > time() ? $v : false; }
function set_transient($k, $v, $ttl) { file_put_contents(_f("t_$k"), serialize([time() + $ttl, $v])); }
function delete_transient($k) { @unlink(_f("t_$k")); }
function wp_safe_remote_get($url, $args) {
    $cache = __DIR__ . '/../.cache/' . md5($url);
    // Les relevés passés sont figés : on réutilise le cache des tests, sauf pour le relevé « maintenant ».
    if (is_file($cache) && !preg_match('/statuses|chunkNumber=' . intdiv(time(), 10) . '/', $url)) {
        return ['code' => 200, 'body' => file_get_contents($cache)];
    }
    $ctx = stream_context_create(['http' => ['header' => "Accept: {$args['headers']['Accept']}\r\nUser-Agent: {$args['user-agent']}\r\n", 'ignore_errors' => true, 'timeout' => 15]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return new WP_Error('http', 'réseau');
    preg_match('/ (\d{3}) /', $http_response_header[0] ?? '', $m);
    if ((int) ($m[1] ?? 0) === 200) @file_put_contents($cache, $body);
    return ['code' => (int) ($m[1] ?? 0), 'body' => $body];
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

require __DIR__ . '/../../suivi-gravelman-ameliore/suivi-gravelman-ameliore.php';
do_action('wp_enqueue_scripts');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/plugin/')) {
    $file = realpath(__DIR__ . '/../../suivi-gravelman-ameliore/' . substr($path, 8));
    if (!$file || !is_file($file)) { http_response_code(404); exit; }
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png'];
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'text/plain'));
    readfile($file);
    exit;
}
if ($path === '/admin-ajax.php') {
    do_action('wp_ajax_nopriv_' . ($_GET['action'] ?? ''));
    exit;
}
$html = call_user_func($GLOBALS['shortcodes']['suivi_gravelman'], [
    'url' => $_GET['url'] ?? 'https://mymotion.dotvision.com/play?s=256059',
    'parcours' => $_GET['parcours'] ?? '350 Gravel',
    'gpx' => $_GET['gpx'] ?? '',
    'coureur' => $_GET['coureur'] ?? '',
]);
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Test Suivi Gravelman</title>
<?php foreach (array_unique($GLOBALS['assets']['css']) as $c) echo "<link rel=stylesheet href=\"$c\">\n"; ?>
<style>body{background:#f1f1f1;margin:0;padding:10px}</style></head><body>
<?php echo $html; ?>
<script><?php echo $GLOBALS['assets']['loc']; ?></script>
<?php foreach (array_unique($GLOBALS['assets']['js']) as $j) echo "<script src=\"$j\"></script>\n"; ?>
</body></html>
