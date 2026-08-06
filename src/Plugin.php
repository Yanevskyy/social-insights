<?php
/**
 * Bootstrap, storage and collection.
 *
 * Metrics are captured daily and stored as snapshots, one row per channel per
 * metric per day. Storing history rather than querying live matters for two
 * reasons: the platforms only keep recent data available, and a quarterly
 * report has to be reproducible months later when the API no longer offers
 * that window.
 *
 * @package SocialInsights
 */

declare(strict_types=1);

namespace ClarityWeb\SocialInsights;

use ClarityWeb\SocialInsights\Provider\LinkedInProvider;
use ClarityWeb\SocialInsights\Provider\MetaProvider;
use ClarityWeb\SocialInsights\Provider\ProviderUnavailable;
use ClarityWeb\SocialInsights\Provider\SocialProvider;

defined('ABSPATH') || exit;

final class Plugin
{
    public const OPTION_SETTINGS = 'si_settings';
    public const CRON_HOOK       = 'si_collect';

    private static ?self $instance = null;

    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'si_snapshots';
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('init', [self::class, 'maybeInstall']);
        add_action(self::CRON_HOOK, [$this, 'collect']);
        add_action('admin_menu', [Admin\DashboardPage::class, 'addMenu']);
        add_action('admin_post_si_save', [Admin\DashboardPage::class, 'handleSave']);
        add_action('admin_post_si_collect', [Admin\DashboardPage::class, 'handleCollect']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 600, 'daily', self::CRON_HOOK);
        }
    }

    public static function activate(): void
    {
        self::install();
    }

    public static function maybeInstall(): void
    {
        if (get_option('si_db_version') !== VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        // One row per channel, metric and day. The unique key makes a repeated
        // collection for the same day an update rather than a duplicate, so
        // running the job twice cannot inflate a report.
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            channel VARCHAR(40) NOT NULL,
            metric VARCHAR(80) NOT NULL,
            metric_label VARCHAR(120) NOT NULL DEFAULT '',
            value BIGINT NOT NULL DEFAULT 0,
            captured_for DATE NOT NULL,
            captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_point (channel, metric, captured_for),
            KEY idx_range (channel, captured_for)
        ) {$charset};");

        update_option('si_db_version', VERSION);
    }

    /**
     * @return array<string,mixed>
     */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION_SETTINGS, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @return array<int,SocialProvider>
     */
    public function providers(): array
    {
        $settings = self::settings();

        return [
            new MetaProvider(
                'instagram',
                (string) ($settings['instagram_id'] ?? ''),
                (string) ($settings['instagram_token'] ?? ''),
                (string) ($settings['graph_endpoint'] ?? '')
            ),
            new MetaProvider(
                'facebook',
                (string) ($settings['facebook_id'] ?? ''),
                (string) ($settings['facebook_token'] ?? ''),
                (string) ($settings['graph_endpoint'] ?? '')
            ),
            new LinkedInProvider(
                (string) ($settings['linkedin_id'] ?? ''),
                (string) ($settings['linkedin_token'] ?? ''),
                (string) ($settings['linkedin_endpoint'] ?? '')
            ),
        ];
    }

    /**
     * Captures yesterday's figures for every configured channel.
     *
     * A channel that fails does not stop the others, and its failure is
     * recorded rather than silently skipped: a gap in a report needs an
     * explanation, not an empty space.
     *
     * @return array<string,string> Channel key to outcome.
     */
    public function collect(?string $day = null): array
    {
        $day    = $day ?? gmdate('Y-m-d', strtotime('-1 day'));
        $since  = $day . ' 00:00:00';
        $until  = $day . ' 23:59:59';
        $result = [];

        foreach ($this->providers() as $provider) {
            if (!$provider->isConfigured()) {
                $result[$provider->key()] = 'not configured';

                continue;
            }

            try {
                $metrics = $provider->metrics($since, $until);

                foreach ($metrics as $name => $metric) {
                    $this->store($provider->key(), $name, $metric['label'], (int) $metric['value'], $day);
                }

                try {
                    $this->store($provider->key(), 'followers', 'Followers', $provider->followers(), $day);
                } catch (ProviderUnavailable) {
                    // Follower count is a nice-to-have; losing it must not lose
                    // the metrics we already collected.
                }

                $result[$provider->key()] = sprintf('%d metrics', count($metrics));
            } catch (ProviderUnavailable $error) {
                $result[$provider->key()] = 'failed: ' . $error->getMessage();
            }
        }

        update_option('si_last_collection', ['at' => current_time('mysql', true), 'result' => $result], false);

        return $result;
    }

    private function store(string $channel, string $metric, string $label, int $value, string $day): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . self::table() . '
                 (channel, metric, metric_label, value, captured_for)
                 VALUES (%s, %s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), metric_label = VALUES(metric_label)',
                $channel,
                $metric,
                $label,
                $value,
                $day
            )
        );
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . Admin\DashboardPage::SLUG) {
            return;
        }

        $file = SI_DIR . 'assets/admin.css';

        wp_enqueue_style(
            'si-admin',
            SI_URL . 'assets/admin.css',
            [],
            is_readable($file) ? (string) filemtime($file) : VERSION
        );
    }
}
