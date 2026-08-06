<?php
/**
 * Quarterly reach and engagement report.
 *
 * The tender asks for "detailed quarterly reports to show the reach and
 * engagement of social media activities". Two decisions shape this class.
 *
 * First, channels are reported separately. Instagram reach, Facebook reach and
 * LinkedIn impressions measure different things, and a combined total would be
 * a bigger number that means less. Where a single figure is genuinely useful it
 * is labelled with what it actually counts.
 *
 * Second, a gap in the data is shown as a gap. If a channel stopped reporting
 * for three weeks, the report says so rather than presenting the remaining
 * weeks as though they were the whole quarter.
 *
 * @package SocialInsights
 */

declare(strict_types=1);

namespace ClarityWeb\SocialInsights\Report;

use ClarityWeb\SocialInsights\Plugin;

defined('ABSPATH') || exit;

final class QuarterlyReport
{
    /**
     * @return array{start:string,end:string,label:string}
     */
    public static function quarterBounds(?string $reference = null): array
    {
        $time    = $reference !== null ? strtotime($reference) : time();
        $month   = (int) gmdate('n', $time);
        $year    = (int) gmdate('Y', $time);
        $quarter = (int) ceil($month / 3);

        $startMonth = ($quarter - 1) * 3 + 1;
        $start      = sprintf('%04d-%02d-01', $year, $startMonth);
        $end        = gmdate('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $startMonth + 2)));

        return ['start' => $start, 'end' => $end, 'label' => sprintf('Q%d %d', $quarter, $year)];
    }

    /**
     * Builds the report for a date range.
     *
     * @return array<string,mixed>
     */
    public static function build(string $start, string $end, ?string $previousStart = null, ?string $previousEnd = null): array
    {
        global $wpdb;

        $table = Plugin::table();

        // The range is validated before anything is counted.
        //
        // Without this, a reversed range produced "0 of -90 days have no data",
        // and an unparseable date silently became a one day period. Both put a
        // meaningless figure in a report that a public body would publish, and
        // a nonsense number is worse than a refusal.
        $startTime = strtotime($start);
        $endTime   = strtotime($end);

        if ($startTime === false || $endTime === false) {
            return self::emptyResult($start, $end, __('The reporting period is not a valid date range.', 'social-insights'));
        }

        if ($endTime < $startTime) {
            // Swapping is safer than failing here: the caller clearly meant a
            // period, and the two dates define it whichever way round they came.
            [$start, $end]         = [$end, $start];
            [$startTime, $endTime] = [$endTime, $startTime];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT channel, metric, metric_label,
                        SUM(value) AS total,
                        COUNT(DISTINCT captured_for) AS days,
                        MAX(value) AS peak
                 FROM {$table}
                 WHERE captured_for BETWEEN %s AND %s AND metric <> 'followers'
                 GROUP BY channel, metric, metric_label
                 ORDER BY channel, metric",
                $start,
                $end
            ),
            ARRAY_A
        );

        $expectedDays = (int) (($endTime - $startTime) / DAY_IN_SECONDS) + 1;
        $channels     = [];

        foreach ((array) $rows as $row) {
            $channel = (string) $row['channel'];

            $channels[$channel] ??= ['metrics' => [], 'coverage' => 0, 'followers' => null];

            $channels[$channel]['metrics'][] = [
                'metric' => (string) $row['metric'],
                'label'  => (string) $row['metric_label'],
                'total'  => (int) $row['total'],
                'peak'   => (int) $row['peak'],
                'days'   => (int) $row['days'],
            ];

            $channels[$channel]['coverage'] = max($channels[$channel]['coverage'], (int) $row['days']);
        }

        // Followers is a point in time, not something to sum across a quarter.
        foreach (array_keys($channels) as $channel) {
            $latest = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT value FROM {$table}
                     WHERE channel = %s AND metric = 'followers' AND captured_for BETWEEN %s AND %s
                     ORDER BY captured_for DESC LIMIT 1",
                    $channel,
                    $start,
                    $end
                )
            );

            $channels[$channel]['followers'] = $latest !== null ? (int) $latest : null;
            $channels[$channel]['gap_days']  = max(0, $expectedDays - $channels[$channel]['coverage']);
        }

        $comparison = null;

        if ($previousStart !== null && $previousEnd !== null) {
            $comparison = self::compare($channels, $previousStart, $previousEnd);
        }

        return [
            'start'         => $start,
            'end'           => $end,
            'expected_days' => $expectedDays,
            'channels'      => $channels,
            'comparison'    => $comparison,
            'generated_at'  => current_time('mysql', true),
        ];
    }

    /**
     * Result for a period that cannot be reported on.
     *
     * Carries the reason rather than looking like a quiet quarter with no
     * activity. Those are different facts and the report must not conflate them.
     *
     * @return array<string,mixed>
     */
    private static function emptyResult(string $start, string $end, string $reason): array
    {
        return [
            'start'         => $start,
            'end'           => $end,
            'expected_days' => 0,
            'channels'      => [],
            'comparison'    => null,
            'error'         => $reason,
            'generated_at'  => current_time('mysql', true),
        ];
    }

    /**
     * Change against the previous period, per metric.
     *
     * @param array<string,mixed> $current
     * @return array<string,array<string,array{previous:int,change:float}>>
     */
    private static function compare(array $current, string $start, string $end): array
    {
        global $wpdb;

        $table = Plugin::table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT channel, metric, SUM(value) AS total
                 FROM {$table}
                 WHERE captured_for BETWEEN %s AND %s AND metric <> 'followers'
                 GROUP BY channel, metric",
                $start,
                $end
            ),
            ARRAY_A
        );

        $previous = [];

        foreach ((array) $rows as $row) {
            $previous[(string) $row['channel']][(string) $row['metric']] = (int) $row['total'];
        }

        $comparison = [];

        foreach ($current as $channel => $data) {
            foreach ($data['metrics'] as $metric) {
                $before = $previous[$channel][$metric['metric']] ?? null;

                // No previous figure means no comparison. Showing "+100%"
                // because the earlier period is empty would be a fabricated
                // improvement.
                if ($before === null || $before === 0) {
                    continue;
                }

                $comparison[$channel][$metric['metric']] = [
                    'previous' => $before,
                    'change'   => round((($metric['total'] - $before) / $before) * 100, 1),
                ];
            }
        }

        return $comparison;
    }

    /**
     * Plain text version, for pasting into a document or an email.
     *
     * @param array<string,mixed> $report
     */
    public static function asText(array $report): string
    {
        $lines = [];

        $lines[] = sprintf('Social media report: %s to %s', $report['start'], $report['end']);
        $lines[] = str_repeat('=', 48);
        $lines[] = '';

        if (!empty($report['error'])) {
            $lines[] = (string) $report['error'];

            return implode("\n", $lines);
        }

        if ($report['channels'] === []) {
            $lines[] = 'No data was collected for this period.';

            return implode("\n", $lines);
        }

        foreach ($report['channels'] as $channel => $data) {
            $lines[] = strtoupper($channel);

            if ($data['followers'] !== null) {
                $lines[] = sprintf('  Followers at period end: %s', number_format($data['followers']));
            }

            foreach ($data['metrics'] as $metric) {
                $line = sprintf('  %-16s %s', $metric['label'], number_format($metric['total']));

                $change = $report['comparison'][$channel][$metric['metric']]['change'] ?? null;

                if ($change !== null) {
                    $line .= sprintf(' (%+.1f%% vs previous period)', $change);
                }

                $lines[] = $line;
            }

            if ($data['gap_days'] > 0) {
                $lines[] = sprintf(
                    '  Note: %d of %d days have no data for this channel.',
                    $data['gap_days'],
                    $report['expected_days']
                );
            }

            $lines[] = '';
        }

        $lines[] = 'Figures are per platform and are not combined, because each';
        $lines[] = 'platform measures reach differently.';

        return implode("\n", $lines);
    }
}
