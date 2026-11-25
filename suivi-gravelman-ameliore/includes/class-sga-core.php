<?php
/**
 * Suivi Gravelman amélioré — logique métier indépendante de WordPress.
 *
 * Tout ce qui est ici peut être testé en ligne de commande (php tests/run.php).
 */

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

final class SGA_Core {
    public const API_BASE = 'https://motion-api-v2.dotvision.com/api';

    /** Pas de l'historique reconstruit (secondes) et nombre de relevés historiques traités par actualisation. */
    public const BACKFILL_STEP = 900;
    public const BACKFILL_PER_RUN = 24;

    /** Au-delà de ce délai sans position, un coureur en course est signalé « Signal perdu ». */
    public const STALE_SECONDS = 45 * 60;

    /** Noms exclus par défaut (photographes, vidéastes, organisation…). */
    public const DEFAULT_EXCLUDE = '/photograph|movie|vid[eé]o|cam[eé]ra|staff|organisat|m[ée]dia|ouvreur|balai|s[ée]curit[ée]|drone/iu';

    /* ------------------------------------------------------------------
     * Paramètres
     * ---------------------------------------------------------------- */

    /** Extrait l'identifiant d'étape DotVision d'une URL de suivi (…/play?s=256059) ou d'un nombre. */
    public static function stage_id_from_url(string $url): ?int {
        $url = trim($url);
        if (preg_match('/^\d{3,}$/', $url)) {
            return (int) $url;
        }
        $query = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);
        foreach (['s', 'stage', 'stageId'] as $key) {
            if (!empty($params[$key]) && preg_match('/^\d+$/', (string) $params[$key])) {
                return (int) $params[$key];
            }
        }
        if (preg_match('#/stages?/(\d+)#', $url, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Ramène un libellé de parcours à une clé stable : « 500 Route », « 500R », « 500 r »,
     * « 500R_GRAVELMAN_ORLEANS_2026 » → « 500R » ; « 350 Gravel », « 350G » → « 350G ».
     */
    public static function category_key(string $label): ?string {
        $text = strtolower(self::strip_accents($label));
        if (!preg_match('/(\d{2,4})\s*[-_ ]?\s*(gravel|route|road|g|r)(?![a-z])/', $text, $m)
            && !preg_match('/(\d{2,4})\s*[-_ ]?\s*(gravel|route|road|g|r)/', $text, $m)) {
            return null;
        }
        $letter = in_array($m[2], ['gravel', 'g'], true) ? 'G' : 'R';
        return $m[1] . $letter;
    }

    public static function strip_accents(string $value): string {
        if (class_exists('Normalizer')) {
            $value = (string) Normalizer::normalize($value, Normalizer::FORM_D);
            return (string) preg_replace('/\p{Mn}+/u', '', $value);
        }
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $converted === false ? $value : $converted;
    }

    /** Reconnaît une URL OpenRunner et renvoie l'URL d'export GPX publique correspondante. */
    public static function openrunner_export_url(string $url): ?string {
        if (!preg_match('#openrunner\.com#i', $url)) {
            return null;
        }
        if (preg_match('#(?:route-details|route|routes|itineraire)/(\d+)#i', $url, $m)
            || preg_match('#[?&](?:id|routeId)=(\d+)#i', $url, $m)
            || preg_match('#/(\d{5,})(?:[/?\#]|$)#', $url, $m)) {
            return 'https://api.openrunner.com/api/v2/routes/' . $m[1] . '/export/gpx-track';
        }
        return null;
    }

    /* ------------------------------------------------------------------
     * Tracés
     * ---------------------------------------------------------------- */

    /** Lit un fichier GPX (trkpt, à défaut rtept) et renvoie la liste [lat, lon]. */
    public static function parse_gpx(string $xml): array {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            throw new RuntimeException('Le fichier GPX est illisible.');
        }
        $doc->registerXPathNamespace('g', $doc->getNamespaces(true)[''] ?? 'http://www.topografix.com/GPX/1/1');
        $nodes = $doc->xpath('//g:trkpt') ?: $doc->xpath('//trkpt') ?: $doc->xpath('//g:rtept') ?: $doc->xpath('//rtept') ?: [];
        $name = (string) ($doc->xpath('//g:trk/g:name')[0] ?? $doc->xpath('//g:metadata/g:name')[0] ?? $doc->xpath('//g:rte/g:name')[0] ?? '');
        $points = [];
        foreach ($nodes as $node) {
            $lat = (float) $node['lat'];
            $lon = (float) $node['lon'];
            if ($lat === 0.0 && $lon === 0.0) {
                continue;
            }
            $points[] = [$lat, $lon];
        }
        if (count($points) < 2) {
            throw new RuntimeException('Le fichier GPX ne contient pas de trace.');
        }
        return ['name' => trim($name), 'points' => $points];
    }

    /**
     * Extrait des tracés officiels DotVision (stages/{id}/gpx?preferredFormat=geojson)
     * celui qui est associé à la catégorie demandée.
     */
    public static function dotvision_track_for_category(array $geojson, string $category_key): ?array {
        $geometries = $geojson['geometry']['geometries'] ?? [];
        $fence = $geojson['properties']['geofence'] ?? [];
        $nodes = $fence['nodes'] ?? [];
        $shapes = $fence['shapes'] ?? [];
        $node_index = [];
        foreach ($nodes as $i => $node) {
            if (!empty($node['@id'])) {
                $node_index[$node['@id']] = $i;
            }
        }

        $collect = static function (int $i) use (&$collect, $nodes, $shapes, $geometries): array {
            $node = $nodes[$i] ?? [];
            $lines = [];
            if (isset($node['ishape'])) {
                $g = $shapes[$node['ishape']]['igeometry'] ?? null;
                if ($g !== null && isset($geometries[$g]['coordinates'])) {
                    $lines[] = $geometries[$g]['coordinates'];
                }
            }
            foreach ($node['ichildren'] ?? [] as $child) {
                $lines = array_merge($lines, $collect((int) $child));
            }
            return $lines;
        };

        $candidates = [];
        foreach ($fence['configs'] ?? [] as $config) {
            if (($config['@type'] ?? '') !== 'TrackConfig' || !isset($node_index[$config['nodeId'] ?? ''])) {
                continue;
            }
            $i = $node_index[$config['nodeId']];
            $match = false;
            foreach ($config['participantCategories'] ?? [] as $label) {
                if (self::category_key((string) $label) === $category_key) {
                    $match = true;
                }
            }
            $name = (string) ($nodes[$i]['displayName'] ?? '');
            if (!$match && self::category_key($name) === $category_key) {
                $match = true;
            }
            if ($match) {
                $candidates[] = ['name' => $name, 'lines' => $collect($i)];
            }
        }
        if (!$candidates) {
            return null;
        }
        $points = [];
        foreach ($candidates[0]['lines'] as $line) {
            foreach ($line as $c) {
                $points[] = [(float) $c[1], (float) $c[0]];
            }
        }
        return count($points) >= 2 ? ['name' => $candidates[0]['name'], 'points' => $points] : null;
    }

    /** Calcule les distances cumulées et allège la trace (un point tous les ~25 m au minimum). */
    public static function build_route(string $name, array $points, string $source): array {
        $out = [];
        $km = 0.0;
        $last_kept = null;
        $prev = null;
        $count = count($points);
        foreach ($points as $i => $p) {
            if ($prev !== null) {
                $km += self::haversine_km($prev[0], $prev[1], $p[0], $p[1]);
            }
            $prev = $p;
            if ($last_kept === null || $i === $count - 1 || ($km - $last_kept) >= 0.025) {
                $out[] = [round($p[0], 6), round($p[1], 6), round($km, 3)];
                $last_kept = $km;
            }
        }
        return [
            'name'        => $name,
            'source'      => $source,
            'distance_km' => round($km, 3),
            'loop'        => self::haversine_km($points[0][0], $points[0][1], $points[$count - 1][0], $points[$count - 1][1]) < 1.0,
            'points'      => $out,
        ];
    }

    public static function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $r = 6371.0088;
        $dlat = deg2rad($lat2 - $lat1);
        $dlon = deg2rad($lon2 - $lon1);
        $a = sin($dlat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlon / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /* ------------------------------------------------------------------
     * Projection d'une position sur le tracé
     * ---------------------------------------------------------------- */

    /**
     * Renvoie les passages possibles du tracé à proximité d'une position :
     * liste de ['km' => distance sur le tracé, 'off' => écart en km], triée par km.
     */
    public static function candidates(array $route, float $lat, float $lon): array {
        $pts = $route['points'];
        $kx = 111.320 * cos(deg2rad($lat));
        $ky = 110.574;
        $hits = [];
        $best = INF;
        $n = count($pts);
        for ($i = 0; $i < $n - 1; $i++) {
            $ax = ($pts[$i][1] - $lon) * $kx;
            $ay = ($pts[$i][0] - $lat) * $ky;
            $bx = ($pts[$i + 1][1] - $lon) * $kx;
            $by = ($pts[$i + 1][0] - $lat) * $ky;
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $len2)) : 0;
            $px = $ax + $t * $dx;
            $py = $ay + $t * $dy;
            $d = sqrt($px * $px + $py * $py);
            $hits[$i] = [$d, $pts[$i][2] + $t * ($pts[$i + 1][2] - $pts[$i][2])];
            $best = min($best, $d);
        }
        $limit = max(0.4, $best + 0.15);
        $result = [];
        $run = null;
        foreach ($hits as $i => [$d, $km]) {
            if ($d <= $limit) {
                if ($run === null || $i !== $run['last'] + 1) {
                    if ($run !== null) {
                        $result[] = ['km' => $run['km'], 'off' => $run['off']];
                    }
                    $run = ['km' => $km, 'off' => $d, 'last' => $i];
                } else {
                    if ($d < $run['off']) {
                        $run['km'] = $km;
                        $run['off'] = $d;
                    }
                    $run['last'] = $i;
                }
            } elseif ($run !== null && $km - $run['km'] > 0.5) {
                $result[] = ['km' => $run['km'], 'off' => $run['off']];
                $run = null;
            }
        }
        if ($run !== null) {
            $result[] = ['km' => $run['km'], 'off' => $run['off']];
        }
        usort($result, static fn($a, $b) => $a['km'] <=> $b['km']);
        return $result;
    }

    /**
     * Choisit le passage le plus plausible : le premier passage « en avant » du dernier point connu
     * (gère les allers-retours et les boucles), ou, sans historique, celui qui colle le mieux au temps écoulé.
     */
    public static function choose_km(array $candidates, ?float $previous_km, float $elapsed_hours, float $total_km): ?array {
        if (!$candidates) {
            return null;
        }
        if ($previous_km !== null) {
            $forward = array_values(array_filter($candidates, static fn($c) => $c['km'] >= $previous_km - 3.0));
            if (!$forward) {
                return ['km' => $previous_km, 'off' => $candidates[0]['off'], 'held' => true];
            }
            $choice = $forward[0];
            // Ne jamais reculer : une petite oscillation GPS garde le dernier km connu.
            if ($choice['km'] < $previous_km) {
                $choice['km'] = $previous_km;
            }
            return $choice;
        }
        $guess = min($total_km, max(0, $elapsed_hours) * 20.0);
        usort($candidates, static fn($a, $b) => abs($a['km'] - $guess) <=> abs($b['km'] - $guess));
        return $candidates[0];
    }

    /* ------------------------------------------------------------------
     * Données DotVision
     * ---------------------------------------------------------------- */

    /** Coureurs inscrits sur le parcours demandé. */
    public static function select_racers(array $activities, string $category_key, string $exclude_csv = ''): array {
        $exclude = array_filter(array_map(static fn($n) => self::norm_name($n), explode(',', $exclude_csv)));
        $racers = [];
        foreach ($activities as $activity) {
            $participant = is_array($activity['participant'] ?? null) ? $activity['participant'] : [];
            $keys = [];
            $staff = false;
            foreach ($participant['tags'] ?? [] as $tag) {
                $k = self::category_key((string) $tag);
                if ($k !== null) {
                    $keys[$k] = true;
                } elseif (preg_match('/[ée]quipe|staff|orga|m[ée]dia|presse/iu', (string) $tag)) {
                    $staff = true;
                }
            }
            // Organisation : tag « Équipe » ou inscrit sur plusieurs parcours à la fois.
            if (!isset($keys[$category_key]) || $staff || count($keys) > 2) {
                continue;
            }
            $name = trim((string) ($participant['name'] ?? ''));
            if ($name === '' || preg_match(self::DEFAULT_EXCLUDE, $name) || in_array(self::norm_name($name), $exclude, true)) {
                continue;
            }
            $devices = [];
            foreach ($activity['datasources'] ?? [] as $source) {
                if (!empty($source['linkedDeviceId'])) {
                    $devices[] = (string) $source['linkedDeviceId'];
                }
            }
            $racers[(string) $activity['id']] = [
                'id'      => (string) $activity['id'],
                'bib'     => (string) ($participant['bib'] ?? ''),
                'name'    => $name,
                'country' => (string) ($participant['country'] ?? ''),
                'devices' => $devices,
            ];
        }
        return $racers;
    }

    /** Toutes les catégories présentes dans une épreuve (pour l'aide à la configuration). */
    /** « 500R » → « 500 Route », « 350G » → « 350 Gravel ». */
    public static function course_label(string $key): string {
        return preg_match('/^(\d+)([GR])$/', $key, $m) ? $m[1] . ($m[2] === 'G' ? ' Gravel' : ' Route') : $key;
    }

    /** Parcours proposés au visiteur : ceux qui comptent au moins un coureur (organisation exclue). */
    public static function list_courses(array $activities, string $exclude_csv = ''): array {
        $courses = [];
        foreach (array_keys(self::list_categories($activities)) as $k) {
            $n = count(self::select_racers($activities, (string) $k, $exclude_csv));
            if ($n > 0) {
                $courses[] = ['key' => (string) $k, 'label' => self::course_label((string) $k), 'count' => $n];
            }
        }
        usort($courses, static fn($a, $b) => [(int) $b['key'], substr($a['key'], -1)] <=> [(int) $a['key'], substr($b['key'], -1)]);
        return $courses;
    }

    public static function list_categories(array $activities): array {
        $found = [];
        foreach ($activities as $activity) {
            foreach ($activity['participant']['tags'] ?? [] as $tag) {
                $k = self::category_key((string) $tag);
                if ($k !== null) {
                    $found[$k]['labels'][(string) $tag] = true;
                    $found[$k]['count'] = ($found[$k]['count'] ?? 0) + 1;
                }
            }
        }
        ksort($found, SORT_NATURAL);
        return array_map(static fn($v) => ['labels' => array_keys($v['labels']), 'count' => $v['count']], $found);
    }

    /** Dernière position connue de chaque balise dans un relevé data/snap : deviceId → [ts, lat, lon, speed]. */
    public static function positions_from_snapshot(array $snapshot): array {
        $positions = [];
        foreach ($snapshot['r'] ?? [] as $record) {
            $device = (string) ($record['d'] ?? '');
            foreach ($record['s'] ?? [] as $series) {
                if (($series['m'] ?? '') !== 'location') {
                    continue;
                }
                foreach ($series['s'] ?? [] as $sample) {
                    $coords = $sample['v']['coordinates'] ?? null;
                    $ts = isset($sample['t']) ? strtotime((string) $sample['t']) : false;
                    if (!$ts || !is_array($coords) || count($coords) < 2) {
                        continue;
                    }
                    if (!isset($positions[$device]) || $ts > $positions[$device]['ts']) {
                        $positions[$device] = [
                            'ts'    => $ts,
                            'lat'   => (float) $coords[1],
                            'lon'   => (float) $coords[0],
                            'speed' => isset($sample['v']['e']['s']) ? (float) $sample['v']['e']['s'] : null,
                        ];
                    }
                }
            }
        }
        return $positions;
    }

    /* ------------------------------------------------------------------
     * Suivi dans le temps
     * ---------------------------------------------------------------- */

    /**
     * Intègre un relevé de positions dans l'état mémorisé de chaque coureur.
     * L'état conserve le km atteint (jamais en recul) et l'heure d'arrivée.
     */
    public static function apply_snapshot(array $state, array $racers, array $positions, array $route, int $start_ts): array {
        $total = (float) $route['distance_km'];
        $end = end($route['points']);
        foreach ($racers as $id => $racer) {
            $pos = null;
            foreach ($racer['devices'] as $device) {
                if (isset($positions[$device]) && ($pos === null || $positions[$device]['ts'] > $pos['ts'])) {
                    $pos = $positions[$device];
                }
            }
            if ($pos === null || $pos['ts'] < $start_ts - 1800) {
                continue;
            }
            $rider = $state[$id] ?? [];
            if (!empty($rider['finish_ts'])) {
                continue;
            }
            if (!empty($rider['ts']) && $pos['ts'] <= $rider['ts']) {
                continue;
            }
            $candidates = self::candidates($route, $pos['lat'], $pos['lon']);
            $choice = self::choose_km(
                $candidates,
                isset($rider['km']) ? (float) $rider['km'] : null,
                ($pos['ts'] - $start_ts) / 3600,
                $total
            );
            $rider['prev_km'] = $rider['km'] ?? null;
            $rider['prev_ts'] = $rider['ts'] ?? null;

            // Retour en arrière après un saut : le coureur s'était trompé de direction
            // (ex. engagé sur le retour avant d'avoir fait la boucle). Confirmé sur 2 relevés → on annule le saut.
            if (!empty($choice['held']) && !empty($rider['jumps'])) {
                $last = end($rider['jumps']);
                $behind = array_values(array_filter($candidates, static fn($c) => $c['km'] >= $last['from'] - 3.0 && $c['km'] < $last['to'] - 3.0));
                if ($behind) {
                    $back = $rider['back'] ?? null;
                    if ($back !== null && $behind[0]['km'] >= $back - 3.0) {
                        array_pop($rider['jumps']);
                        $rider['skipped_km'] = max(0, round(($rider['skipped_km'] ?? 0) - $last['skipped'], 1));
                        $rider['km'] = $behind[0]['km'];
                        unset($rider['back']);
                        $choice = $behind[0];
                    } else {
                        $rider['back'] = $behind[0]['km'];
                    }
                } else {
                    unset($rider['back']);
                }
            } else {
                unset($rider['back']);
            }
            if ($choice !== null && empty($choice['held']) && isset($rider['km'], $rider['ts'])) {
                // Saut impossible à vélo (> 45 km/h + 5 km) : parcours coupé ou mauvais passage.
                // On ne l'accepte que s'il est confirmé au relevé suivant.
                $plausible = (($pos['ts'] - (int) $rider['ts']) / 3600) * 45.0 + 5.0;
                $jump = $choice['km'] - (float) $rider['km'];
                if ($jump > $plausible) {
                    $pending = $rider['pending'] ?? null;
                    if ($pending === null || $choice['km'] < $pending['km'] - 3.0) {
                        $rider['pending'] = ['km' => $choice['km'], 'skipped' => $jump - $plausible + 5.0];
                        $choice = null; // on garde l'ancien km en attendant confirmation
                    } else {
                        $rider['skipped_km'] = round(($rider['skipped_km'] ?? 0) + $pending['skipped'], 1);
                        $rider['jumps'][] = ['from' => (float) $rider['km'], 'to' => $choice['km'], 'skipped' => $pending['skipped']];
                        unset($rider['pending']);
                    }
                } else {
                    unset($rider['pending']);
                }
            }
            $rider['ts'] = $pos['ts'];
            $rider['lat'] = $pos['lat'];
            $rider['lon'] = $pos['lon'];
            $rider['speed'] = $pos['speed'];
            if ($choice !== null) {
                $rider['km'] = round(min($total, $choice['km']), 3);
                $rider['off'] = round($choice['off'], 3);
            } elseif (!isset($rider['km'])) {
                $rider['km'] = 0.0;
            }
            $near_end = self::haversine_km($pos['lat'], $pos['lon'], $end[0], $end[1]) <= 0.35;
            $elapsed_ok = ($pos['ts'] - $start_ts) >= ($total / 45) * 3600;
            if ($near_end && $elapsed_ok && isset($rider['km']) && $rider['km'] >= $total - max(2.0, 0.03 * $total)) {
                $rider['km'] = $total;
                $rider['finish_ts'] = $pos['ts'];
                $rider['finish_refine'] = $rider['prev_ts'] ?? null;
            }
            $state[$id] = $rider;
        }
        return $state;
    }

    /**
     * Affine l'heure d'arrivée entre deux relevés espacés : dichotomie sur les relevés DotVision.
     * $fetch_positions(int $ts): array  renvoie les positions au temps $ts.
     */
    public static function refine_finish(array $rider, array $racer, array $route, callable $fetch_positions, int $start_ts): array {
        $low = (int) ($rider['finish_refine'] ?? 0);
        $high = (int) $rider['finish_ts'];
        if ($low <= 0 || $high - $low <= 90) {
            unset($rider['finish_refine']);
            return $rider;
        }
        $end = end($route['points']);
        $total = (float) $route['distance_km'];
        for ($i = 0; $i < 7 && $high - $low > 60; $i++) {
            $mid = intdiv($low + $high, 2);
            $positions = $fetch_positions($mid);
            $pos = null;
            foreach ($racer['devices'] as $device) {
                if (isset($positions[$device])) {
                    $pos = $positions[$device];
                }
            }
            $at_end = $pos && $pos['ts'] > $low
                && self::haversine_km($pos['lat'], $pos['lon'], $end[0], $end[1]) <= 0.35
                && ($pos['ts'] - $start_ts) >= ($total / 45) * 3600;
            if ($at_end) {
                $high = $pos['ts'];
            } else {
                $low = $mid;
            }
        }
        $rider['finish_ts'] = $high;
        unset($rider['finish_refine']);
        return $rider;
    }

    /* ------------------------------------------------------------------
     * Classement
     * ---------------------------------------------------------------- */

    public static function build_ranking(array $racers, array $state, array $statuses, array $route, int $start_ts, int $now): array {
        $total = (float) $route['distance_km'];
        $capture_ts = 0;
        foreach ($state as $rider) {
            $capture_ts = max($capture_ts, (int) ($rider['ts'] ?? 0));
        }
        $capture_ts = $capture_ts ?: min($now, max($start_ts, $now));

        $status_map = [];
        foreach ($statuses as $status) {
            if (!empty($status['activityId'])) {
                $status_map[(string) $status['activityId']] = strtoupper((string) ($status['status']['name'] ?? ''));
            }
        }

        $rows = [];
        foreach ($racers as $id => $racer) {
            $rider = $state[$id] ?? [];
            $official = $status_map[$id] ?? '';
            $has_pos = !empty($rider['ts']);
            $km = isset($rider['km']) ? (float) $rider['km'] : null;

            $skipped = (float) ($rider['skipped_km'] ?? 0);
            if (preg_match('/DNF|ABANDON|ABD|DSQ|DISQ/', $official)) {
                $key = 'dnf';
            } elseif ($skipped >= 10) {
                $key = 'short';
            } elseif (!empty($rider['finish_ts'])) {
                $key = 'finish';
            } elseif (preg_match('/DNS|NON.?PARTANT/', $official)) {
                $key = 'dns';
            } elseif ($has_pos && ($km ?? 0) < 1.0 && $now > $start_ts + 3 * 3600) {
                $key = 'dns';
            } elseif (!$has_pos) {
                $key = $now > $start_ts + 3 * 3600 ? 'dns' : 'waiting';
            } elseif ($capture_ts - (int) $rider['ts'] > self::STALE_SECONDS) {
                $key = 'stale';
            } else {
                $key = 'racing';
            }

            $event_ts = $key === 'finish' ? (int) $rider['finish_ts'] : ($has_pos ? (int) $rider['ts'] : null);
            $elapsed = $event_ts ? max(0, $event_ts - $start_ts) : null;
            $rows[] = [
                'id'              => $id,
                'rank'            => null,
                'bib'             => $racer['bib'],
                'name'            => $racer['name'],
                'country'         => $racer['country'],
                'status_key'      => $key,
                'status'          => [
                    'finish'  => 'Arrivé',
                    'racing'  => 'En course',
                    'stale'   => 'Signal perdu',
                    'short'   => 'Parcours écourté',
                    'dnf'     => 'Abandon',
                    'dns'     => 'Non-partant',
                    'waiting' => 'En attente',
                ][$key],
                'skipped_km'      => $skipped >= 10 ? round($skipped) : null,
                'distance_km'     => $km !== null ? round($km, 1) : null,
                'remaining_km'    => $km !== null ? round(max(0, $total - $km), 1) : null,
                'progress'        => $km !== null && $total > 0 ? round(100 * $km / $total, 1) : null,
                'avg_speed_kmh'   => $km !== null && $elapsed ? round($km / ($elapsed / 3600), 1) : null,
                'off_route_km'    => isset($rider['off']) ? (float) $rider['off'] : null,
                'latitude'        => $rider['lat'] ?? null,
                'longitude'       => $rider['lon'] ?? null,
                'elapsed_seconds' => $elapsed,
                'finish_time'     => $key === 'finish' ? gmdate('c', (int) $rider['finish_ts']) : null,
                'last_position'   => $has_pos ? gmdate('c', (int) $rider['ts']) : null,
                'delay_minutes'   => $has_pos ? round(max(0, $capture_ts - (int) $rider['ts']) / 60, 1) : null,
            ];
        }

        $order = ['finish' => 0, 'racing' => 1, 'stale' => 1, 'short' => 2, 'dnf' => 3, 'waiting' => 4, 'dns' => 5];
        usort($rows, static function (array $a, array $b) use ($order): int {
            $group = $order[$a['status_key']] <=> $order[$b['status_key']];
            if ($group !== 0) return $group;
            if ($a['status_key'] === 'finish') return $a['elapsed_seconds'] <=> $b['elapsed_seconds'];
            if (in_array($a['status_key'], ['racing', 'stale', 'short', 'dnf'], true)) {
                $cmp = ($b['distance_km'] ?? -1) <=> ($a['distance_km'] ?? -1);
                if ($cmp !== 0) return $cmp;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        $rank = 0;
        $counts = ['total' => count($rows), 'finished' => 0, 'racing' => 0, 'stale' => 0, 'short' => 0, 'dnf' => 0, 'non_starters' => 0, 'waiting' => 0];
        foreach ($rows as &$row) {
            if (in_array($row['status_key'], ['finish', 'racing', 'stale'], true)) {
                $row['rank'] = ++$rank;
            }
            $counts[[
                'finish' => 'finished', 'racing' => 'racing', 'stale' => 'stale', 'short' => 'short',
                'dnf' => 'dnf', 'dns' => 'non_starters', 'waiting' => 'waiting',
            ][$row['status_key']]]++;
        }
        unset($row);

        return ['captured_at' => gmdate('c', $capture_ts), 'counts' => $counts, 'racers' => $rows];
    }

    public static function norm_name(string $value): string {
        return trim((string) preg_replace('/\s+/', ' ', strtolower(self::strip_accents($value))));
    }
}
