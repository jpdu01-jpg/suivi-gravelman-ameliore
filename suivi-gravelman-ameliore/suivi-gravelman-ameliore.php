<?php
/**
 * Plugin Name: BikeXplore – Suivi Gravelman amélioré
 * Description: Classement live d'un parcours d'une épreuve Gravelman (ou toute épreuve DotVision) : carte, classement filtré par parcours, coureurs suivis au choix du visiteur. Shortcode [suivi_gravelman url="…" parcours="500 Route" gpx="…"].
 * Version: 2.1.1
 * Author: BikeXplore
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 * Update URI: https://github.com/jpdu01-jpg/suivi-gravelman-ameliore
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-sga-core.php';
require_once __DIR__ . '/includes/class-sga-updater.php';

final class SGA_Plugin {
    public const VERSION = '2.1.1';
    private const INSTANCES_OPTION = 'sga_instances';
    private const CRON_HOOK = 'sga_refresh';
    private const DATA_TTL = 55;           // secondes entre deux interrogations de DotVision
    private const TIME_BUDGET = 18;        // secondes max par actualisation

    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action(self::CRON_HOOK, [self::class, 'cron_refresh']);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
        add_shortcode('suivi_gravelman', [self::class, 'shortcode']);
        foreach (['sga_data', 'sga_route'] as $action) {
            add_action('wp_ajax_' . $action, [self::class, 'ajax_' . $action]);
            add_action('wp_ajax_nopriv_' . $action, [self::class, 'ajax_' . $action]);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 30, 'sga_every_minute', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function cron_schedules(array $schedules): array {
        $schedules['sga_every_minute'] = ['interval' => 60, 'display' => 'Toutes les minutes (Suivi Gravelman)'];
        return $schedules;
    }

    /* ------------------------------------------------------------------
     * Shortcode
     * ---------------------------------------------------------------- */

    public static function register_assets(): void {
        $url = plugin_dir_url(__FILE__);
        wp_register_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_register_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_register_style('sga', $url . 'assets/suivi.css', ['leaflet'], self::VERSION);
        wp_register_script('sga', $url . 'assets/suivi.js', ['leaflet'], self::VERSION, true);
    }

    public static function shortcode($atts): string {
        $atts = shortcode_atts([
            'url'      => '',
            'parcours' => '',
            'gpx'      => '',
            'coureur'  => '',
            'exclure'  => '',
            'titre'    => '',
            'club'     => 'BikeXplore',
        ], $atts, 'suivi_gravelman');

        $stage_id = SGA_Core::stage_id_from_url((string) $atts['url']);
        $category = SGA_Core::category_key((string) $atts['parcours']);
        if (!$stage_id || !$category) {
            return self::editor_notice('Paramètres incomplets : indiquez url="https://mymotion.dotvision.com/play?s=…" et parcours="500 Route" (ou 350G, 500R…).');
        }

        $instance = [
            'stage_id' => $stage_id,
            'category' => $category,
            'label'    => sanitize_text_field((string) $atts['parcours']),
            'gpx'      => esc_url_raw(trim((string) $atts['gpx'])),
            'exclude'  => sanitize_text_field((string) $atts['exclure']),
            'source'   => 'https://mymotion.dotvision.com/play?s=' . $stage_id,
        ];
        $key = substr(md5($stage_id . '|' . $category . '|' . $instance['gpx'] . '|' . $instance['exclude']), 0, 12);
        self::register_instance($key, $instance);

        wp_enqueue_style('sga');
        wp_enqueue_script('sga');
        wp_localize_script('sga', 'SGAConfig', [
            'ajax'     => admin_url('admin-ajax.php'),
            'interval' => 60000,
        ]);

        $logo = plugin_dir_url(__FILE__) . 'assets/bikexplore-logo.png';
        $title = $atts['titre'] !== '' ? $atts['titre'] : 'Classement live ' . $category;
        ob_start();
        ?>
        <section class="bx-gm" data-sga
                 data-key="<?php echo esc_attr($key); ?>"
                 data-default-riders="<?php echo esc_attr((string) $atts['coureur']); ?>"
                 data-club="<?php echo esc_attr((string) $atts['club']); ?>">
            <header class="bx-gm__header">
                <div class="bx-gm__brand">
                    <span class="bx-gm__logo"><img src="<?php echo esc_url($logo); ?>" alt="BikeXplore"></span>
                    <div>
                        <p class="bx-gm__eyebrow" data-sga-event>GRAVELMAN</p>
                        <h2><?php echo esc_html($title); ?></h2>
                        <p class="bx-gm__meta" data-sga-meta>Chargement des positions…</p>
                    </div>
                </div>
                <div class="bx-gm__refresh" aria-live="polite">
                    <span class="bx-gm__pulse" aria-hidden="true"></span>
                    <span data-sga-countdown>Actualisation…</span>
                </div>
            </header>

            <div class="bx-gm__stats" data-sga-stats aria-label="Résumé de la course"></div>

            <div class="bx-gm__follow">
                <form class="bx-gm__follow-form" data-sga-follow-form autocomplete="off">
                    <label for="sga-follow-<?php echo esc_attr($key); ?>">Suivre un coureur <small>(facultatif)</small></label>
                    <div class="bx-gm__follow-input">
                        <input id="sga-follow-<?php echo esc_attr($key); ?>" type="text" list="sga-list-<?php echo esc_attr($key); ?>"
                               data-sga-follow-input placeholder="Nom ou dossard…">
                        <datalist id="sga-list-<?php echo esc_attr($key); ?>" data-sga-datalist></datalist>
                        <button type="submit">Suivre</button>
                    </div>
                    <p class="bx-gm__follow-hint" data-sga-follow-hint>Votre choix est mémorisé sur cet appareil. Vous pouvez aussi cliquer sur ☆ dans le classement.</p>
                </form>
                <div class="bx-gm__cards" data-sga-cards></div>
            </div>

            <div class="bx-gm__map-card">
                <div class="bx-gm__map-heading">
                    <div>
                        <h3>Position des concurrents</h3>
                        <p data-sga-map-meta>Chargement du tracé…</p>
                    </div>
                    <span><i class="bx-gm__map-dot"></i> En course <i class="bx-gm__map-dot bx-gm__map-dot--club"></i> Suivi</span>
                </div>
                <div class="bx-gm__map" data-sga-map></div>
            </div>

            <div class="bx-gm__toolbar">
                <input type="search" data-sga-search placeholder="Rechercher un nom ou un dossard…" autocomplete="off" aria-label="Rechercher un concurrent">
                <label class="bx-gm__only"><input type="checkbox" data-sga-only> Coureurs suivis uniquement</label>
            </div>

            <div class="bx-gm__table-wrap">
                <table class="bx-gm__table">
                    <thead>
                        <tr>
                            <th aria-label="Suivre"></th>
                            <th>Rang</th>
                            <th>Dossard</th>
                            <th>Concurrent</th>
                            <th>Statut</th>
                            <th>Distance</th>
                            <th>Reste</th>
                            <th>Moy.</th>
                            <th>Durée</th>
                            <th>Dernière position</th>
                        </tr>
                    </thead>
                    <tbody data-sga-body>
                        <tr><td colspan="10" class="bx-gm__empty">Chargement…</td></tr>
                    </tbody>
                </table>
            </div>

            <p class="bx-gm__notice">
                Classement indicatif calculé par BikeXplore à partir des positions GPS DotVision, projetées sur le tracé du parcours.
                Un retard de balise ou une perte GPS peut momentanément modifier l’ordre ; « Parcours écourté » signale un saut
                de plus de 10 km sur le tracé.
                <a href="<?php echo esc_url($instance['source']); ?>" target="_blank" rel="noopener">Voir le suivi officiel</a>.
            </p>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function editor_notice(string $message): string {
        return current_user_can('edit_posts')
            ? '<p style="border:2px solid #b91c1c;padding:12px;color:#b91c1c"><strong>Suivi Gravelman :</strong> ' . esc_html($message) . '</p>'
            : '';
    }

    private static function register_instance(string $key, array $instance): void {
        $all = get_option(self::INSTANCES_OPTION, []);
        $all = is_array($all) ? $all : [];
        $existing = $all[$key] ?? null;
        if ($existing && ($existing['seen'] ?? 0) > time() - HOUR_IN_SECONDS) {
            return;
        }
        $instance['seen'] = time();
        $all[$key] = $instance;
        // Oubli des suivis non affichés depuis 60 jours.
        foreach ($all as $k => $v) {
            if (($v['seen'] ?? 0) < time() - 60 * DAY_IN_SECONDS) {
                unset($all[$k]);
                delete_option('sga_state_' . $k);
                delete_option('sga_route_' . $k);
            }
        }
        update_option(self::INSTANCES_OPTION, $all, false);
    }

    private static function instance(string $key): ?array {
        $all = get_option(self::INSTANCES_OPTION, []);
        return is_array($all) && isset($all[$key]) ? $all[$key] : null;
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ---------------------------------------------------------------- */

    private static function request_key(): string {
        return preg_replace('/[^a-f0-9]/', '', (string) ($_GET['k'] ?? ''));
    }

    public static function ajax_sga_data(): void {
        $key = self::request_key();
        $instance = self::instance($key);
        if (!$instance) {
            wp_send_json(['error' => true, 'message' => 'Suivi inconnu.'], 404);
        }
        $data = get_transient('sga_data_' . $key);
        if (!is_array($data) || (time() - ($data['generated'] ?? 0)) > ($data['ttl'] ?? self::DATA_TTL)) {
            $fresh = self::refresh($key, $instance);
            if (!is_wp_error($fresh)) {
                $data = $fresh;
            } elseif (is_array($data)) {
                $data['warning'] = 'La dernière actualisation a échoué ; affichage du relevé précédent.';
            } else {
                wp_send_json(['error' => true, 'message' => $fresh->get_error_message()], 502);
            }
        }
        nocache_headers();
        wp_send_json($data, 200);
    }

    public static function ajax_sga_route(): void {
        $key = self::request_key();
        $instance = self::instance($key);
        if (!$instance) {
            wp_send_json(['error' => true, 'message' => 'Suivi inconnu.'], 404);
        }
        try {
            $route = self::route($key, $instance);
        } catch (Throwable $e) {
            wp_send_json(['error' => true, 'message' => $e->getMessage()], 502);
        }
        header('Cache-Control: public, max-age=3600');
        wp_send_json($route, 200);
    }

    /* ------------------------------------------------------------------
     * Actualisation
     * ---------------------------------------------------------------- */

    public static function cron_refresh(): void {
        $all = get_option(self::INSTANCES_OPTION, []);
        if (!is_array($all)) {
            return;
        }
        foreach ($all as $key => $instance) {
            if (($instance['seen'] ?? 0) < time() - 14 * DAY_IN_SECONDS) {
                continue;
            }
            $data = get_transient('sga_data_' . $key);
            if (is_array($data) && (time() - ($data['generated'] ?? 0)) < ($data['ttl'] ?? self::DATA_TTL)) {
                continue;
            }
            $stage = get_transient('sga_stage_' . $instance['stage_id']);
            if (is_array($stage)) {
                $begin = strtotime($stage['begin']);
                $end = strtotime($stage['end']);
                $live = time() >= $begin - HOUR_IN_SECONDS && time() <= $end + 12 * HOUR_IN_SECONDS;
                if (!$live && is_array($data) && !empty($data['complete'])) {
                    continue;
                }
                if (time() < $begin - HOUR_IN_SECONDS) {
                    continue;
                }
            }
            self::refresh($key, $instance);
        }
    }

    /** @return array|WP_Error */
    private static function refresh(string $key, array $instance) {
        $lock = 'sga_lock_' . $key;
        if (get_transient($lock)) {
            $cached = get_transient('sga_data_' . $key);
            return is_array($cached) ? $cached : new WP_Error('busy', 'Actualisation en cours, réessayez dans un instant.');
        }
        set_transient($lock, 1, 60);
        $t0 = microtime(true);
        try {
            $B = SGA_Core::API_BASE;
            $id = (int) $instance['stage_id'];

            $stage = get_transient('sga_stage_' . $id);
            if (!is_array($stage)) {
                $stage = self::fetch_json("$B/stages/$id");
                $stage = ['name' => (string) ($stage['name'] ?? ''), 'begin' => (string) $stage['begin'], 'end' => (string) $stage['end'], 'eventId' => (string) ($stage['eventId'] ?? '')];
                if (!empty($stage['eventId'])) {
                    try {
                        $event = self::fetch_json("$B/events/{$stage['eventId']}");
                        $stage['event'] = (string) ($event['name'] ?? '');
                    } catch (Throwable $e) {
                        // nom d'épreuve facultatif
                    }
                }
                set_transient('sga_stage_' . $id, $stage, 3 * HOUR_IN_SECONDS);
            }
            $start = strtotime($stage['begin']);
            $end = strtotime($stage['end']);

            $activities = get_transient('sga_activities_' . $id);
            if (!is_array($activities)) {
                $activities = self::fetch_json("$B/stages/$id/activities/all?includeParticipants=true&includeDataSources=true");
                set_transient('sga_activities_' . $id, $activities, 15 * MINUTE_IN_SECONDS);
            }
            $racers = SGA_Core::select_racers($activities, $instance['category'], $instance['exclude'] ?? '');
            if (!$racers) {
                $cats = implode(', ', array_keys(SGA_Core::list_categories($activities)));
                throw new RuntimeException("Aucun coureur sur le parcours {$instance['category']}. Parcours disponibles : $cats.");
            }

            $route = self::route($key, $instance);

            $state = get_option('sga_state_' . $key, []);
            if (!is_array($state) || ($state['route'] ?? '') !== $route['hash']) {
                $state = ['route' => $route['hash'], 'cursor' => $start, 'riders' => []];
            }
            $now = time();
            $horizon = min($now, $end + 12 * HOUR_IN_SECONDS);
            $fetch = static function (int $ts) use ($B, $id): array {
                return SGA_Core::positions_from_snapshot(
                    self::fetch_json("$B/data/snap/stage/$id?chunkNumber=" . intdiv($ts, 10) . '&raceAgainstTime=false')
                );
            };

            // Reconstitution progressive de l'historique (utile si la page est créée en cours d'épreuve).
            $steps = 0;
            while ($horizon > $start && $state['cursor'] < $horizon - SGA_Core::BACKFILL_STEP
                && $steps < SGA_Core::BACKFILL_PER_RUN && microtime(true) - $t0 < self::TIME_BUDGET) {
                $state['riders'] = SGA_Core::apply_snapshot($state['riders'], $racers, $fetch((int) $state['cursor']), $route, $start);
                $state['cursor'] += SGA_Core::BACKFILL_STEP;
                $steps++;
            }
            $caught_up = $state['cursor'] >= $horizon - SGA_Core::BACKFILL_STEP;
            if ($caught_up && $horizon > $start) {
                $state['riders'] = SGA_Core::apply_snapshot($state['riders'], $racers, $fetch($horizon), $route, $start);
                $state['cursor'] = max($state['cursor'], $horizon);
            }
            // Heure d'arrivée précise (un coureur par actualisation).
            foreach ($state['riders'] as $rid => $rider) {
                if (!empty($rider['finish_refine']) && isset($racers[$rid]) && microtime(true) - $t0 < self::TIME_BUDGET) {
                    $state['riders'][$rid] = SGA_Core::refine_finish($rider, $racers[$rid], $route, $fetch, $start);
                    break;
                }
            }

            $statuses = [];
            try {
                $statuses = self::fetch_json("$B/activities/statuses/snap?stageId=$id&timestamp=" . rawurlencode(gmdate('Y-m-d\TH:i:s.000\Z', $horizon)));
            } catch (Throwable $e) {
                // statuts facultatifs
            }

            $ranking = SGA_Core::build_ranking($racers, $state['riders'], $statuses, $route, $start, (int) min($horizon, $state['cursor']));
            $pending_refine = count(array_filter($state['riders'], static fn($r) => !empty($r['finish_refine'])));
            $complete = $caught_up && $pending_refine === 0 && $now > $end + 12 * HOUR_IN_SECONDS;
            update_option('sga_state_' . $key, $state, false);

            $data = array_merge($ranking, [
                'event'        => $stage['event'] ?? $stage['name'],
                'stage'        => $stage['name'],
                'category'     => $instance['category'],
                'label'        => $instance['label'],
                'route_name'   => $route['name'],
                'route_km'     => $route['distance_km'],
                'route_source' => $route['source'],
                'start_at'     => gmdate('c', $start),
                'end_at'       => gmdate('c', $end),
                'source_url'   => $instance['source'],
                'history'      => $horizon > $start ? min(100, round(100 * ($state['cursor'] - $start) / max(1, $horizon - $start))) : 100,
                'complete'     => $complete,
                'generated'    => $now,
                'ttl'          => $complete ? 6 * HOUR_IN_SECONDS : ($caught_up ? self::DATA_TTL : 5),
            ]);
            set_transient('sga_data_' . $key, $data, DAY_IN_SECONDS);
            return $data;
        } catch (Throwable $e) {
            return new WP_Error('sga', $e->getMessage() ?: 'Les données DotVision sont momentanément indisponibles.');
        } finally {
            delete_transient($lock);
        }
    }

    /** Tracé de référence : GPX fourni (OpenRunner ou URL), sinon tracé officiel DotVision du parcours. */
    private static function route(string $key, array $instance): array {
        $cached = get_option('sga_route_' . $key);
        if (is_array($cached) && !empty($cached['points'])) {
            return $cached;
        }
        $gpx = (string) ($instance['gpx'] ?? '');
        if ($gpx !== '') {
            $export = SGA_Core::openrunner_export_url($gpx);
            try {
                $parsed = SGA_Core::parse_gpx(self::fetch_body($export ?? $gpx, 'application/gpx+xml,application/xml,*/*'));
            } catch (Throwable $e) {
                throw new RuntimeException($export
                    ? 'Le parcours OpenRunner n’est pas accessible (il doit être public), ou téléversez le GPX dans la médiathèque et indiquez son URL.'
                    : 'Le fichier GPX n’a pas pu être lu : ' . $e->getMessage());
            }
            $route = SGA_Core::build_route($parsed['name'] ?: $instance['label'], $parsed['points'], $export ? 'OpenRunner' : 'GPX');
        } else {
            $geo = self::fetch_json(SGA_Core::API_BASE . '/stages/' . (int) $instance['stage_id'] . '/gpx?preferredFormat=geojson');
            $track = SGA_Core::dotvision_track_for_category($geo, $instance['category']);
            if (!$track) {
                throw new RuntimeException('Pas de tracé DotVision pour ' . $instance['category'] . ' : indiquez gpx="…" (lien OpenRunner public ou fichier GPX).');
            }
            $route = SGA_Core::build_route($track['name'], $track['points'], 'DotVision');
        }
        $route['hash'] = md5(wp_json_encode($route['points']));
        update_option('sga_route_' . $key, $route, false);
        return $route;
    }

    private static function fetch_body(string $url, string $accept = 'application/json'): string {
        if (!wp_http_validate_url($url)) {
            throw new RuntimeException('URL invalide.');
        }
        $response = wp_safe_remote_get($url, [
            'timeout'     => 15,
            'redirection' => 3,
            'headers'     => ['Accept' => $accept],
            'user-agent'  => 'BikeXplore-Suivi-Gravelman/' . self::VERSION,
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            throw new RuntimeException('Réponse HTTP ' . wp_remote_retrieve_response_code($response));
        }
        return (string) wp_remote_retrieve_body($response);
    }

    private static function fetch_json(string $url): array {
        $decoded = json_decode(self::fetch_body($url), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Format DotVision non valide.');
        }
        return $decoded;
    }
}

SGA_Plugin::init();
SGA_Updater::init(__FILE__, SGA_Plugin::VERSION);
register_deactivation_hook(__FILE__, static function (): void {
    SGA_Plugin::deactivate();
    SGA_Updater::deactivate();
});
