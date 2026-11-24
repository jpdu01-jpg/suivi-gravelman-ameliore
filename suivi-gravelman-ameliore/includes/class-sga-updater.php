<?php
/**
 * Mises à jour du plugin depuis les « releases » GitHub du dépôt public.
 *
 * - WordPress affiche « Mise à jour disponible » dès qu'une version plus récente est publiée.
 * - Option « Mises à jour express » (lien sur la page Extensions) : la nouvelle version
 *   est installée automatiquement dans les 5 minutes, sans intervention.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SGA_Updater {
    public const REPO = 'jpdu01-jpg/suivi-gravelman-ameliore';
    private const ASSET = 'suivi-gravelman-ameliore.zip';
    private const CACHE = 'sga_latest_release';
    private const EXPRESS_OPTION = 'sga_express_updates';
    private const CRON_HOOK = 'sga_express_check';

    /** @var string */
    private static $file;
    /** @var string */
    private static $version;

    public static function init(string $file, string $version): void {
        self::$file = $file;
        self::$version = $version;
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject_update']);
        add_filter('plugins_api', [self::class, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [self::class, 'fix_folder'], 10, 4);
        add_filter('plugin_action_links_' . plugin_basename($file), [self::class, 'action_links']);
        add_action('admin_post_sga_express', [self::class, 'toggle_express']);
        add_action('load-plugins.php', [self::class, 'refresh_on_plugins_page']);
        add_filter('auto_update_plugin', [self::class, 'auto_update'], 20, 2);
        add_action(self::CRON_HOOK, [self::class, 'express_check']);
        add_filter('cron_schedules', static function (array $s): array {
            $s['sga_every_five_minutes'] = ['interval' => 300, 'display' => 'Toutes les 5 minutes (Suivi Gravelman)'];
            return $s;
        });
        if (get_option(self::EXPRESS_OPTION) && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'sga_every_five_minutes', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /** Dernière version publiée sur GitHub (mise en cache quelques minutes). */
    public static function latest(bool $force = false): ?array {
        $cached = get_site_transient(self::CACHE);
        if (!$force && is_array($cached)) {
            return $cached['version'] ? $cached : null;
        }
        $response = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Suivi-Gravelman-Updater'],
        ]);
        $release = null;
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $json = json_decode(wp_remote_retrieve_body($response), true);
            foreach ($json['assets'] ?? [] as $asset) {
                if (($asset['name'] ?? '') === self::ASSET) {
                    $release = [
                        'version' => ltrim((string) $json['tag_name'], 'vV'),
                        'package' => (string) $asset['browser_download_url'],
                        'url'     => (string) $json['html_url'],
                        'notes'   => (string) ($json['body'] ?? ''),
                        'date'    => (string) ($json['published_at'] ?? ''),
                    ];
                }
            }
        }
        // En cas d'échec, on ne réessaie pas avant 2 minutes.
        set_site_transient(self::CACHE, $release ?? ['version' => null], $release ? 4 * MINUTE_IN_SECONDS : 2 * MINUTE_IN_SECONDS);
        return $release;
    }

    private static function newer(?array $release): bool {
        return $release && version_compare($release['version'], self::$version, '>');
    }

    public static function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }
        $basename = plugin_basename(self::$file);
        $release = self::latest();
        $item = (object) [
            'id'          => self::REPO,
            'slug'        => dirname($basename),
            'plugin'      => $basename,
            'new_version' => $release['version'] ?? self::$version,
            'url'         => 'https://github.com/' . self::REPO,
            'package'     => $release['package'] ?? '',
        ];
        if (self::newer($release)) {
            $transient->response[$basename] = $item;
            unset($transient->no_update[$basename]);
        } else {
            $item->new_version = self::$version;
            $transient->no_update[$basename] = $item;
            unset($transient->response[$basename]);
        }
        return $transient;
    }

    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== dirname(plugin_basename(self::$file))) {
            return $result;
        }
        $release = self::latest();
        return (object) [
            'name'          => 'BikeXplore – Suivi Gravelman amélioré',
            'slug'          => dirname(plugin_basename(self::$file)),
            'version'       => $release['version'] ?? self::$version,
            'author'        => 'BikeXplore',
            'homepage'      => 'https://github.com/' . self::REPO,
            'download_link' => $release['package'] ?? '',
            'last_updated'  => $release['date'] ?? '',
            'sections'      => [
                'changelog' => '<pre>' . esc_html($release['notes'] ?? '') . '</pre>',
            ],
        ];
    }

    /** Garantit que le dossier installé garde le même nom. */
    public static function fix_folder($source, $remote_source, $upgrader, $hook_extra = []) {
        if (($hook_extra['plugin'] ?? '') !== plugin_basename(self::$file)) {
            return $source;
        }
        $wanted = trailingslashit($remote_source) . dirname(plugin_basename(self::$file)) . '/';
        if (untrailingslashit($source) !== untrailingslashit($wanted) && is_dir($source)) {
            global $wp_filesystem;
            if ($wp_filesystem && $wp_filesystem->move($source, $wanted, true)) {
                return $wanted;
            }
        }
        return $source;
    }

    /** Sur la page Extensions : vérifie GitHub sans attendre le contrôle WordPress (toutes les 12 h). */
    public static function refresh_on_plugins_page(): void {
        $release = self::latest(true);
        $current = get_site_transient('update_plugins');
        $basename = plugin_basename(self::$file);
        $known = is_object($current) && isset($current->response[$basename]) ? $current->response[$basename]->new_version : null;
        if (self::newer($release) && $known !== $release['version']) {
            delete_site_transient('update_plugins');
            wp_update_plugins();
        }
    }

    public static function action_links(array $links): array {
        $on = (bool) get_option(self::EXPRESS_OPTION);
        $url = wp_nonce_url(admin_url('admin-post.php?action=sga_express&on=' . ($on ? 0 : 1)), 'sga_express');
        $links[] = $on
            ? '<a href="' . esc_url($url) . '">Mises à jour express : <strong>activées</strong> (désactiver)</a>'
            : '<a href="' . esc_url($url) . '">Activer les mises à jour express</a>';
        $links[] = '<a href="' . esc_url(admin_url('plugins.php')) . '">Vérifier les mises à jour</a>';
        return $links;
    }

    public static function toggle_express(): void {
        if (!current_user_can('update_plugins') || !check_admin_referer('sga_express')) {
            wp_die('Action non autorisée.');
        }
        $on = !empty($_GET['on']);
        update_option(self::EXPRESS_OPTION, $on ? 1 : 0);
        wp_clear_scheduled_hook(self::CRON_HOOK);
        if ($on) {
            wp_schedule_event(time() + 60, 'sga_every_five_minutes', self::CRON_HOOK);
        }
        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    public static function auto_update($update, $item) {
        if (get_option(self::EXPRESS_OPTION) && ($item->plugin ?? '') === plugin_basename(self::$file)) {
            return true;
        }
        return $update;
    }

    /** Toutes les 5 minutes en mode express : installe la nouvelle version dès qu'elle est publiée. */
    public static function express_check(): void {
        if (!get_option(self::EXPRESS_OPTION) || !self::newer(self::latest(true))) {
            return;
        }
        delete_site_transient('update_plugins');
        wp_update_plugins();
        if (function_exists('wp_maybe_auto_update')) {
            wp_maybe_auto_update();
        }
    }
}
