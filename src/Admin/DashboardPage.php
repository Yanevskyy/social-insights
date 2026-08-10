<?php
/**
 * Social dashboard and quarterly report.
 *
 * One screen answers the two things the contract requires: what happened last
 * month, and a quarterly report of reach and engagement that can be handed over
 * as it stands.
 *
 * @package SocialInsights
 */

declare(strict_types=1);

namespace ClarityWeb\SocialInsights\Admin;

use ClarityWeb\SocialInsights\Plugin;
use ClarityWeb\SocialInsights\Secret;
use ClarityWeb\SocialInsights\Provider\ProviderUnavailable;
use ClarityWeb\SocialInsights\Report\QuarterlyReport;

defined('ABSPATH') || exit;

final class DashboardPage
{
    public const SLUG  = 'social-insights';
    private const NONCE = 'si_settings';

    /** Post lists are cached for an hour; refreshing is an explicit action. */
    private const POSTS_TRANSIENT = 'si_recent_posts';

    /** Connection test results, kept just long enough to be shown once. */
    private const TEST_TRANSIENT = 'si_connection_test';

    /** Enough to see what worked, short enough to read at a glance. */
    private const POSTS_SHOWN = 10;

    public static function addMenu(): void
    {
        add_menu_page(
            __('Social Insights', 'social-insights'),
            __('Social Insights', 'social-insights'),
            'edit_others_posts',
            self::SLUG,
            [self::class, 'render'],
            'dashicons-chart-bar',
            28
        );
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'social-insights'));
        }

        check_admin_referer(self::NONCE);

        $post     = wp_unslash($_POST);
        $existing = Plugin::settings();
        $settings = [];

        foreach (['instagram_id', 'facebook_id', 'linkedin_id', 'graph_endpoint', 'linkedin_endpoint'] as $field) {
            $settings[$field] = sanitize_text_field((string) ($post[$field] ?? ''));
        }

        // A blank token field keeps the stored one. Otherwise saving an
        // unrelated field would silently disconnect a channel.
        foreach (['instagram_token', 'facebook_token', 'linkedin_token'] as $field) {
            // Trimmed before anything else. A token copied out of a developer
            // console arrives with a trailing newline more often than not, and
            // the API rejects it with a message about the token being invalid,
            // which sends the administrator looking for the wrong problem.
            $value = trim((string) ($post[$field] ?? ''));

            if ($value === '') {
                // Carried over as stored, still encrypted. Decrypting and
                // re-encrypting would churn the value for no reason.
                $settings[$field] = (string) ($existing[$field] ?? '');

                continue;
            }

            $settings[$field] = Secret::encrypt($value);
        }

        update_option(Plugin::OPTION_SETTINGS, $settings, false);

        wp_safe_redirect(add_query_arg('saved', '1', menu_page_url(self::SLUG, false)));
        exit;
    }

    public static function handleCollect(): void
    {
        if (!current_user_can('edit_others_posts')) {
            wp_die(esc_html__('You do not have permission to do that.', 'social-insights'));
        }

        check_admin_referer(self::NONCE . '_collect');

        $day = isset($_POST['day']) ? sanitize_text_field(wp_unslash($_POST['day'])) : null;

        Plugin::instance()->collect($day ?: null);

        wp_safe_redirect(add_query_arg('collected', '1', menu_page_url(self::SLUG, false)));
        exit;
    }

    /**
     * A date inside the quarter being reported on, or null for the current one.
     */
    private static function requestedQuarter(): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
        $value = isset($_GET['quarter']) ? sanitize_text_field(wp_unslash($_GET['quarter'])) : '';

        if (!preg_match('/^(\d{4})-Q([1-4])$/', $value, $matches)) {
            return null;
        }

        $year  = (int) $matches[1];
        $month = ((int) $matches[2] - 1) * 3 + 1;

        return sprintf('%04d-%02d-15', $year, $month);
    }

    /**
     * Quarters there is actually data for, newest first.
     *
     * Built from what was collected rather than from a fixed range, so the
     * selector never offers a quarter that would come back empty.
     *
     * @return array<int,string>
     */
    private static function availableQuarters(): array
    {
        global $wpdb;

        $rows = $wpdb->get_col(
            'SELECT DISTINCT CONCAT(YEAR(captured_for), "-Q", QUARTER(captured_for))
             FROM ' . Plugin::table() . '
             ORDER BY MIN(captured_for) DESC'
        );

        $quarters = array_values(array_filter(array_map('strval', (array) $rows)));

        $current = QuarterlyReport::quarterBounds();
        $label   = str_replace(' ', '-', $current['label']);
        $label   = preg_replace('/^Q(\d) (\d{4})$/', '$2-Q$1', $current['label']) ?: $label;

        if (!in_array($label, $quarters, true)) {
            array_unshift($quarters, $label);
        }

        rsort($quarters);

        return $quarters;
    }

    /**
     * Asks each channel whether it can actually be reached.
     *
     * The status cards say "configured", which only means an id and a token
     * were typed in. Whether they work is a different question, and the answer
     * used to arrive a day later as a failed collection nobody was watching.
     * testConnection was implemented on both providers and called from
     * nowhere.
     */
    public static function handleTest(): void
    {
        if (!current_user_can('edit_others_posts')) {
            wp_die(esc_html__('You do not have permission to do that.', 'social-insights'));
        }

        check_admin_referer(self::NONCE . '_test');

        $results = [];

        foreach (Plugin::instance()->providers() as $provider) {
            $results[$provider->key()] = $provider->isConfigured()
                ? $provider->testConnection()
                : ['ok' => false, 'message' => __('Not connected.', 'social-insights')];
        }

        set_transient(self::TEST_TRANSIENT, $results, 5 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg('tested', '1', menu_page_url(self::SLUG, false)));
        exit;
    }

    /**
     * Drops the cached post list so the next page load fetches it again.
     *
     * Refreshing is a deliberate action rather than something that happens on
     * every page load. Three social APIs queried on each visit would make this
     * screen as slow as the slowest of them and would spend the rate limit on
     * people who only came to read the quarterly figures.
     */
    public static function handleRefreshPosts(): void
    {
        if (!current_user_can('edit_others_posts')) {
            wp_die(esc_html__('You do not have permission to do that.', 'social-insights'));
        }

        check_admin_referer(self::NONCE . '_posts');

        delete_transient(self::POSTS_TRANSIENT);

        wp_safe_redirect(add_query_arg('refreshed', '1', menu_page_url(self::SLUG, false)));
        exit;
    }

    /**
     * Posts for the current quarter, one entry per channel.
     *
     * Each channel is fetched separately and its failure is recorded rather
     * than thrown, so one dead token does not blank the whole section. An
     * unreachable channel says so; it never falls back to older numbers
     * presented as current ones.
     *
     * @return array<int,array{label:string,posts:array<int,array<string,mixed>>,error:?string}>
     */
    private static function collectPosts(string $since, string $until): array
    {
        $cached = get_transient(self::POSTS_TRANSIENT);

        if (is_array($cached) && ($cached['range'] ?? '') === $since . '/' . $until) {
            return $cached['channels'];
        }

        $channels = [];

        foreach (Plugin::instance()->providers() as $provider) {
            if (!$provider->isConfigured()) {
                $channels[] = [
                    'label' => $provider->label(),
                    'posts' => [],
                    'error' => __('Not connected.', 'social-insights'),
                ];

                continue;
            }

            try {
                $posts = $provider->posts($since, $until);
            } catch (ProviderUnavailable $error) {
                $channels[] = [
                    'label' => $provider->label(),
                    'posts' => [],
                    'error' => $error->getMessage(),
                ];

                continue;
            }

            usort(
                $posts,
                static fn(array $a, array $b): int => (int) $b['engagement'] <=> (int) $a['engagement']
            );

            $channels[] = [
                'label' => $provider->label(),
                'posts' => array_slice($posts, 0, self::POSTS_SHOWN),
                'error' => null,
            ];
        }

        set_transient(
            self::POSTS_TRANSIENT,
            ['range' => $since . '/' . $until, 'channels' => $channels],
            HOUR_IN_SECONDS
        );

        return $channels;
    }

    /**
     * Follower counts over the period, per channel.
     *
     * The figures were already being collected daily and shown as a single
     * number at the end of the quarter, which answers "how many" and not "which
     * way is this going". The second question is the one a quarterly report is
     * written to answer.
     */
    private static function renderFollowers(string $start, string $end): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT channel, captured_for, value
                 FROM ' . Plugin::table() . "
                 WHERE metric = 'followers' AND captured_for BETWEEN %s AND %s
                 ORDER BY channel, captured_for",
                $start,
                $end
            ),
            ARRAY_A
        );

        $series = [];

        foreach ((array) $rows as $row) {
            $series[(string) $row['channel']][(string) $row['captured_for']] = (int) $row['value'];
        }

        if ($series === []) {
            return;
        }

        printf('<h2 class="title">%s</h2>', esc_html__('Followers over the period', 'social-insights'));

        foreach ($series as $channel => $points) {
            if (count($points) < 2) {
                printf(
                    '<p class="description">%s</p>',
                    esc_html(sprintf(
                        /* translators: %s: channel name. */
                        __('%s: not enough days collected yet to draw a line.', 'social-insights'),
                        ucfirst((string) $channel)
                    ))
                );

                continue;
            }

            $values = array_values($points);
            $first  = (int) reset($values);
            $last   = (int) end($values);

            // The line is scaled between the lowest and highest value rather
            // than from zero. Follower counts move by fractions of a percent,
            // and a chart anchored at zero draws that as a flat line, which
            // says "nothing happened" about a quarter where something did.
            $low   = min($values);
            $high  = max($values);
            $range = max(1, $high - $low);

            $width  = 640;
            $height = 90;
            $step   = $width / (count($values) - 1);

            $points2 = [];

            foreach ($values as $index => $value) {
                $points2[] = round($index * $step, 1) . ','
                    . round($height - ((($value - $low) / $range) * ($height - 10)) - 5, 1);
            }

            printf(
                '<h3>%s</h3><p class="description">%s</p>',
                esc_html(ucfirst((string) $channel)),
                esc_html(sprintf(
                    /* translators: 1: starting count, 2: ending count, 3: change. */
                    __('%1$s to %2$s over the period, a change of %3$s.', 'social-insights'),
                    number_format_i18n($first),
                    number_format_i18n($last),
                    ($last - $first >= 0 ? '+' : '') . number_format_i18n($last - $first)
                ))
            );

            printf(
                '<svg class="si-chart" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s" preserveAspectRatio="none">'
                    . '<polyline fill="none" stroke="#2271b1" stroke-width="2" points="%4$s" /></svg>',
                $width,
                $height,
                esc_attr(sprintf(
                    /* translators: 1: channel, 2: starting count, 3: ending count, 4: days. */
                    __('%1$s followers went from %2$s to %3$s over %4$d recorded days.', 'social-insights'),
                    ucfirst((string) $channel),
                    number_format_i18n($first),
                    number_format_i18n($last),
                    count($values)
                )),
                esc_attr(implode(' ', $points2))
            );
        }
    }

    /**
     * The report as CSV.
     *
     * The plain text block covers pasting into an email. A report that needs
     * its own tables needs the numbers as numbers, and retyping them out of a
     * textarea is how a figure ends up wrong in a published document.
     */
    public static function handleExport(): void
    {
        if (!current_user_can('edit_others_posts')) {
            wp_die(esc_html__('You do not have permission to do that.', 'social-insights'));
        }

        check_admin_referer(self::NONCE . '_export');

        $quarter = isset($_POST['quarter']) ? sanitize_text_field(wp_unslash($_POST['quarter'])) : '';
        $inside  = null;

        if (preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $matches)) {
            $inside = sprintf('%04d-%02d-15', (int) $matches[1], ((int) $matches[2] - 1) * 3 + 1);
        }

        $bounds   = QuarterlyReport::quarterBounds($inside);
        $previous = QuarterlyReport::quarterBounds(gmdate('Y-m-d', strtotime($bounds['start'] . ' -1 day')));
        $report   = QuarterlyReport::build($bounds['start'], $bounds['end'], $previous['start'], $previous['end']);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="social-report-' . sanitize_file_name($bounds['label']) . '.csv"');

        $out = fopen('php://output', 'w');

        // Excel reads a UTF-8 file as Windows-1252 without this.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['Channel', 'Metric', 'Total', 'Best day', 'Days with data', 'Change vs previous quarter (%)']);

        foreach ($report['channels'] as $channel => $data) {
            if ($data['followers'] !== null) {
                fputcsv($out, [ucfirst((string) $channel), 'Followers at period end', $data['followers'], '', '', '']);
            }

            foreach ($data['metrics'] as $metric) {
                $change = $report['comparison'][$channel][$metric['metric']]['change'] ?? '';

                fputcsv($out, [
                    ucfirst((string) $channel),
                    $metric['label'],
                    $metric['total'],
                    $metric['peak'],
                    $metric['days'],
                    $change === '' ? '' : $change,
                ]);
            }
        }

        // The caveat travels with the file. Someone opening this in a
        // spreadsheet will be tempted to sum the column, and the sum would be
        // meaningless: the platforms do not count the same thing.
        fputcsv($out, []);
        fputcsv($out, ['Note', 'Figures are per platform and must not be added together, because each platform measures reach differently.']);

        fclose($out);
        exit;
    }

    /**
     * @param array<int,array{label:string,posts:array<int,array<string,mixed>>,error:?string}> $channels
     */
    private static function renderPosts(array $channels): void
    {
        foreach ($channels as $channel) {
            printf('<h3>%s</h3>', esc_html($channel['label']));

            if ($channel['error'] !== null) {
                printf(
                    '<div class="notice notice-warning inline"><p>%s</p></div>',
                    esc_html($channel['error'])
                );

                continue;
            }

            if ($channel['posts'] === []) {
                printf(
                    '<p class="description">%s</p>',
                    esc_html__('No posts published in this period.', 'social-insights')
                );

                continue;
            }

            echo '<table class="wp-list-table widefat fixed striped si-posts"><thead><tr>';
            printf('<th scope="col">%s</th>', esc_html__('Published', 'social-insights'));
            printf('<th scope="col">%s</th>', esc_html__('Post', 'social-insights'));
            printf('<th scope="col" class="si-posts__number">%s</th>', esc_html__('Engagement', 'social-insights'));
            echo '</tr></thead><tbody>';

            foreach ($channel['posts'] as $post) {
                $published = strtotime((string) $post['published']);
                $text      = trim((string) $post['text']);

                echo '<tr>';

                printf(
                    '<td class="si-posts__date">%s</td>',
                    esc_html($published ? wp_date(get_option('date_format'), $published) : '')
                );

                // An empty caption is normal on an image post, so the row says
                // so rather than showing a blank cell that reads as a bug.
                $label = $text !== '' ? $text : __('(no caption)', 'social-insights');

                if ($post['url'] !== '') {
                    printf(
                        '<td><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></td>',
                        esc_url((string) $post['url']),
                        esc_html($label)
                    );
                } else {
                    printf('<td>%s</td>', esc_html($label));
                }

                printf(
                    '<td class="si-posts__number">%s</td>',
                    esc_html(number_format_i18n((int) $post['engagement']))
                );

                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }

    public static function render(): void
    {
        if (!current_user_can('edit_others_posts')) {
            wp_die(esc_html__('You do not have permission to open this page.', 'social-insights'));
        }

        // Which quarter is being looked at. Snapshots go back as far as
        // collection has been running, and a report is asked for after the
        // quarter ends, not during it, so being unable to look back was the
        // first thing anyone would notice.
        $bounds   = QuarterlyReport::quarterBounds(self::requestedQuarter());
        $previous = QuarterlyReport::quarterBounds(gmdate('Y-m-d', strtotime($bounds['start'] . ' -1 day')));
        $report   = QuarterlyReport::build($bounds['start'], $bounds['end'], $previous['start'], $previous['end']);

        ?>
        <div class="wrap si-dashboard">
            <h1><?php esc_html_e('Social Insights', 'social-insights'); ?></h1>

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'social-insights'); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['refreshed'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Post list refreshed.', 'social-insights'); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['collected'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Collection run.', 'social-insights'); ?></p></div>
            <?php endif; ?>

            <?php self::renderChannelStatus(); ?>

            <h2 class="title"><?php echo esc_html(sprintf(
                /* translators: %s: quarter label such as Q3 2026. */
                __('Quarterly report: %s', 'social-insights'),
                $bounds['label']
            )); ?></h2>

            <form method="get" class="si-quarter">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <label class="screen-reader-text" for="si-quarter"><?php esc_html_e('Reporting period', 'social-insights'); ?></label>
                <select name="quarter" id="si-quarter">
                    <?php
                    $selected = preg_replace('/^Q(\d) (\d{4})$/', '$2-Q$1', $bounds['label']);

                    foreach (self::availableQuarters() as $quarter) :
                        ?>
                        <option value="<?php echo esc_attr($quarter); ?>" <?php selected($selected, $quarter); ?>>
                            <?php echo esc_html(preg_replace('/^(\d{4})-Q(\d)$/', 'Q$2 $1', $quarter)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Show', 'social-insights'); ?></button>
            </form>
            <p class="description">
                <?php echo esc_html(sprintf(
                    /* translators: 1: start date, 2: end date. */
                    __('%1$s to %2$s. Figures are shown per platform and never added together, because each platform measures reach differently.', 'social-insights'),
                    $bounds['start'],
                    $bounds['end']
                )); ?>
            </p>

            <?php self::renderReport($report); ?>

            <?php self::renderFollowers($bounds['start'], $bounds['end']); ?>

            <h2 class="title"><?php esc_html_e('Posts in this quarter', 'social-insights'); ?></h2>
            <p class="description">
                <?php esc_html_e('The best performing posts on each channel, most engaged first. Engagement counts are taken from each platform as it reports them and are not compared across platforms.', 'social-insights'); ?>
            </p>

            <?php self::renderPosts(self::collectPosts($bounds['start'], $bounds['end'])); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0">
                <input type="hidden" name="action" value="si_refresh_posts">
                <?php wp_nonce_field(self::NONCE . '_posts'); ?>
                <button type="submit" class="button"><?php esc_html_e('Refresh posts', 'social-insights'); ?></button>
                <span class="description"><?php esc_html_e('Cached for an hour to keep the page fast and stay inside the API rate limits.', 'social-insights'); ?></span>
            </form>

            <h2 class="title"><?php esc_html_e('Plain text version', 'social-insights'); ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 8px">
                <input type="hidden" name="action" value="si_export">
                <input type="hidden" name="quarter" value="<?php echo esc_attr(preg_replace('/^Q(\d) (\d{4})$/', '$2-Q$1', $bounds['label'])); ?>">
                <?php wp_nonce_field(self::NONCE . '_export'); ?>
                <button type="submit" class="button"><?php esc_html_e('Download as CSV', 'social-insights'); ?></button>
                <span class="description"><?php esc_html_e('The same figures as a spreadsheet, for a report that needs its own tables.', 'social-insights'); ?></span>
            </form>
            <p class="description"><?php esc_html_e('Ready to paste into a document or an email.', 'social-insights'); ?></p>
            <textarea class="si-textreport" rows="16" readonly><?php
                echo esc_textarea(QuarterlyReport::asText($report));
            ?></textarea>

            <?php self::renderSettings(); ?>
        </div>
        <?php
    }

    private static function renderChannelStatus(): void
    {
        $last = get_option('si_last_collection', []);

        echo '<div class="si-channels">';

        foreach (Plugin::instance()->providers() as $provider) {
            $configured = $provider->isConfigured();

            printf(
                '<div class="si-channel si-channel--%s"><h3>%s</h3><p>%s</p></div>',
                esc_attr($configured ? 'on' : 'off'),
                esc_html($provider->label()),
                esc_html($configured
                    ? __('Configured', 'social-insights')
                    : __('Not connected', 'social-insights'))
            );
        }

        echo '</div>';

        if (is_array($last) && !empty($last['at'])) {
            $timestamp = strtotime((string) $last['at'] . ' UTC');

            printf(
                '<p class="description">%s</p>',
                esc_html(sprintf(
                    /* translators: %s: date and time of the last collection. */
                    __('Last collection: %s', 'social-insights'),
                    $timestamp ? wp_date(get_option('date_format') . ', ' . get_option('time_format'), $timestamp) : ''
                ))
            );

            // Failures from the last run are shown, not swallowed. A silent gap
            // in a quarterly report is worse than an ugly message here.
            foreach ((array) ($last['result'] ?? []) as $channel => $outcome) {
                if (!str_starts_with((string) $outcome, 'failed')) {
                    continue;
                }

                printf(
                    '<div class="notice notice-warning"><p><strong>%s:</strong> %s</p></div>',
                    esc_html($channel),
                    esc_html((string) $outcome)
                );
            }
        }

        $tested = get_transient(self::TEST_TRANSIENT);

        if (is_array($tested)) {
            foreach ($tested as $channel => $outcome) {
                printf(
                    '<div class="notice notice-%1$s inline"><p><strong>%2$s:</strong> %3$s</p></div>',
                    esc_attr(!empty($outcome['ok']) ? 'success' : 'error'),
                    esc_html(ucfirst((string) $channel)),
                    esc_html((string) ($outcome['message'] ?? ''))
                );
            }

            delete_transient(self::TEST_TRANSIENT);
        }

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0;display:inline-block">
            <input type="hidden" name="action" value="si_test">
            <?php wp_nonce_field(self::NONCE . '_test'); ?>
            <button type="submit" class="button"><?php esc_html_e('Test connections', 'social-insights'); ?></button>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0">
            <input type="hidden" name="action" value="si_collect">
            <?php wp_nonce_field(self::NONCE . '_collect'); ?>
            <label for="si-day"><?php esc_html_e('Collect data for', 'social-insights'); ?></label>
            <input type="date" id="si-day" name="day" value="<?php echo esc_attr(gmdate('Y-m-d', strtotime('-1 day'))); ?>">
            <button type="submit" class="button"><?php esc_html_e('Collect now', 'social-insights'); ?></button>
        </form>
        <?php
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function renderReport(array $report): void
    {
        if ($report['channels'] === []) {
            echo '<div class="notice notice-info inline"><p>'
                . esc_html__('No data collected for this period yet. Connect a channel below and run a collection.', 'social-insights')
                . '</p></div>';

            return;
        }

        foreach ($report['channels'] as $channel => $data) {
            printf('<h3 class="si-channel-title">%s</h3>', esc_html(ucfirst((string) $channel)));

            if ($data['gap_days'] > 0) {
                printf(
                    '<p class="si-gap">%s</p>',
                    esc_html(sprintf(
                        /* translators: 1: days without data, 2: days in the period. */
                        __('%1$d of %2$d days have no data for this channel. The totals below cover the days that were captured.', 'social-insights'),
                        (int) $data['gap_days'],
                        (int) $report['expected_days']
                    ))
                );
            }

            echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
            printf('<th scope="col">%s</th>', esc_html__('Metric', 'social-insights'));
            printf('<th scope="col" style="width:140px">%s</th>', esc_html__('Total', 'social-insights'));
            printf('<th scope="col" style="width:140px">%s</th>', esc_html__('Best day', 'social-insights'));
            printf('<th scope="col" style="width:180px">%s</th>', esc_html__('Vs previous quarter', 'social-insights'));
            echo '</tr></thead><tbody>';

            foreach ($data['metrics'] as $metric) {
                $change = $report['comparison'][$channel][$metric['metric']]['change'] ?? null;

                echo '<tr>';
                printf('<td><strong>%s</strong></td>', esc_html((string) $metric['label']));
                printf('<td>%s</td>', esc_html(number_format((int) $metric['total'])));
                printf('<td>%s</td>', esc_html(number_format((int) $metric['peak'])));

                if ($change === null) {
                    printf('<td><span class="si-dash">%s</span></td>', esc_html__('no earlier data', 'social-insights'));
                } else {
                    printf(
                        '<td><span class="si-change si-change--%s">%+.1f%%</span></td>',
                        esc_attr($change >= 0 ? 'up' : 'down'),
                        $change
                    );
                }

                echo '</tr>';
            }

            echo '</tbody></table>';

            if ($data['followers'] !== null) {
                printf(
                    '<p class="description">%s</p>',
                    esc_html(sprintf(
                        /* translators: %s: follower count. */
                        __('Followers at the end of the period: %s', 'social-insights'),
                        number_format((int) $data['followers'])
                    ))
                );
            }
        }
    }

    private static function renderSettings(): void
    {
        $settings = Plugin::settings();

        $field = static function (string $name, string $label, string $value, string $placeholder = ''): void {
            printf(
                '<tr><th scope="row"><label for="si-%1$s">%2$s</label></th>
                 <td><input type="text" id="si-%1$s" name="%1$s" class="regular-text" value="%3$s" placeholder="%4$s"></td></tr>',
                esc_attr($name),
                esc_html($label),
                esc_attr($value),
                esc_attr($placeholder)
            );
        };

        $secret = static function (string $name, string $label, bool $stored): void {
            printf(
                '<tr><th scope="row"><label for="si-%1$s">%2$s</label></th>
                 <td><input type="password" id="si-%1$s" name="%1$s" class="regular-text" autocomplete="off" placeholder="%3$s"></td></tr>',
                esc_attr($name),
                esc_html($label),
                esc_attr($stored ? __('stored, leave blank to keep', 'social-insights') : __('not set', 'social-insights'))
            );
        };

        ?>
        <h2 class="title"><?php esc_html_e('Channels', 'social-insights'); ?></h2>

        <?php if (Secret::available()) : ?>
            <p class="description">
                <?php esc_html_e('Tokens are encrypted before they are stored, so a database backup does not carry working credentials. Rotating the site security keys in wp-config makes stored tokens unreadable and the channels have to be reconnected.', 'social-insights'); ?>
            </p>
        <?php else : ?>
            <div class="notice notice-warning inline">
                <p>
                    <?php esc_html_e('This server has no libsodium, so tokens are stored as plain text. Anyone with a copy of the database, including any backup, has working access to these accounts. Ask the host to enable the sodium extension.', 'social-insights'); ?>
                </p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="si_save">
            <?php wp_nonce_field(self::NONCE); ?>
            <table class="form-table" role="presentation">
                <?php
                $field('instagram_id', __('Instagram account id', 'social-insights'), (string) ($settings['instagram_id'] ?? ''), '17841400000000000');
                $secret('instagram_token', __('Instagram token', 'social-insights'), !empty($settings['instagram_token']));
                $field('facebook_id', __('Facebook page id', 'social-insights'), (string) ($settings['facebook_id'] ?? ''), '100000000000000');
                $secret('facebook_token', __('Facebook token', 'social-insights'), !empty($settings['facebook_token']));
                $field('linkedin_id', __('LinkedIn organisation id', 'social-insights'), (string) ($settings['linkedin_id'] ?? ''), '12345678');
                $secret('linkedin_token', __('LinkedIn token', 'social-insights'), !empty($settings['linkedin_token']));
                ?>
            </table>

            <h3><?php esc_html_e('Endpoints', 'social-insights'); ?></h3>
            <p class="description">
                <?php esc_html_e('Leave blank to use the official APIs. Override only when pointing at a test environment.', 'social-insights'); ?>
            </p>
            <table class="form-table" role="presentation">
                <?php
                $field('graph_endpoint', __('Graph API endpoint', 'social-insights'), (string) ($settings['graph_endpoint'] ?? ''), 'https://graph.facebook.com/v21.0');
                $field('linkedin_endpoint', __('LinkedIn API endpoint', 'social-insights'), (string) ($settings['linkedin_endpoint'] ?? ''), 'https://api.linkedin.com');
                ?>
            </table>

            <?php submit_button(__('Save channels', 'social-insights')); ?>
        </form>
        <?php
    }
}
