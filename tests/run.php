<?php
// Rejoue une épreuve DotVision complète avec la logique du plugin (hors WordPress).
// Usage : php tests/run.php <url-dotvision> <parcours> [gpx-url-ou-fichier]
require __DIR__ . '/../suivi-gravelman-ameliore/includes/class-sga-core.php';

$cache = __DIR__ . '/.cache';
@mkdir($cache);
function get(string $url): string {
    global $cache;
    $file = $cache . '/' . md5($url);
    if (is_file($file)) return file_get_contents($file);
    $ctx = stream_context_create(['http' => ['header' => "Accept: application/json\r\nUser-Agent: SGA-test\r\n", 'ignore_errors' => true]]);
    $body = file_get_contents($url, false, $ctx);
    if ($body === false || !preg_match('/ 200 /', $http_response_header[0] ?? '')) throw new RuntimeException("HTTP KO $url : " . ($http_response_header[0] ?? '?'));
    file_put_contents($file, $body);
    return $body;
}
function json(string $url): array { return json_decode(get($url), true, 512, JSON_THROW_ON_ERROR); }

[$_, $url, $parcours] = $argv + [null, 'https://mymotion.dotvision.com/play?s=256059', '350 Gravel'];
$gpx = $argv[3] ?? '';
$B = SGA_Core::API_BASE;
$stage_id = SGA_Core::stage_id_from_url($url);
$key = SGA_Core::category_key($parcours);
$stage = json("$B/stages/$stage_id");
$activities = json("$B/stages/$stage_id/activities/all?includeParticipants=true&includeDataSources=true");
echo "Épreuve : {$stage['name']} — parcours $key\n";
echo "Catégories : " . json_encode(SGA_Core::list_categories($activities), JSON_UNESCAPED_UNICODE) . "\n";

if ($gpx) {
    $src = SGA_Core::openrunner_export_url($gpx) ?? $gpx;
    $parsed = SGA_Core::parse_gpx(is_file($gpx) ? file_get_contents($gpx) : get($src));
    $route = SGA_Core::build_route($parsed['name'], $parsed['points'], $gpx);
} else {
    $t = SGA_Core::dotvision_track_for_category(json("$B/stages/$stage_id/gpx?preferredFormat=geojson"), $key);
    $route = SGA_Core::build_route($t['name'], $t['points'], 'dotvision');
}
printf("Tracé : %s — %.1f km, %d points, boucle=%s\n", $route['name'], $route['distance_km'], count($route['points']), $route['loop'] ? 'oui' : 'non');

$racers = SGA_Core::select_racers($activities, $key);
echo count($racers) . " coureurs retenus\n";
$start = strtotime($stage['begin']);
$end = min(time(), strtotime($stage['end']) + 6 * 3600);
$fetch = static fn(int $ts) => SGA_Core::positions_from_snapshot(json("$B/data/snap/stage/$stage_id?chunkNumber=" . intdiv($ts, 10) . "&raceAgainstTime=false"));

$state = [];
$t0 = microtime(true);
for ($ts = $start; $ts <= $end; $ts += SGA_Core::BACKFILL_STEP) {
    $state = SGA_Core::apply_snapshot($state, $racers, $fetch($ts), $route, $start);
}
$state = SGA_Core::apply_snapshot($state, $racers, $fetch($end), $route, $start);
foreach ($state as $id => $r) {
    if (!empty($r['finish_refine'])) $state[$id] = SGA_Core::refine_finish($r, $racers[$id], $route, $fetch, $start);
}
printf("Rejeu en %.1f s\n", microtime(true) - $t0);
$data = SGA_Core::build_ranking($racers, $state, [], $route, $start, $end);
echo json_encode($data['counts']) . "\n";
foreach ($data['racers'] as $r) {
    $h = $r['elapsed_seconds'];
    printf("%3s  #%-4s %-28s %-13s %6s km  %s  %s\n", $r['rank'] ?? '-', $r['bib'], mb_substr($r['name'], 0, 28), $r['status'],
        $r['distance_km'] ?? '—', $h ? sprintf('%2dh%02d', intdiv($h, 3600), intdiv($h % 3600, 60)) : '     ', $r['last_position'] ?? '');
}
