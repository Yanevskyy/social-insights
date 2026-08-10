<?php
/**
 * Unit tests for the reporting logic.
 *
 * Run with:  php tests/run.php
 *
 * Quarter arithmetic and period validation are tested here because the output
 * ends up in a document a public body publishes. A quarter that starts on the
 * wrong day, or a period that reports minus ninety days, is not a cosmetic
 * problem: it is a false figure with a council's name on it.
 *
 * @package SocialInsights
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * Mirrors QuarterlyReport::quarterBounds, which needs no WordPress at all.
 *
 * @return array{start:string,end:string,label:string}
 */
function quarterBounds(?string $reference = null): array
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
 * Mirrors the range validation added after testing found a negative day count.
 *
 * @return array{start:string,end:string,days:int,error:string}
 */
function normaliseRange(string $start, string $end): array
{
    $startTime = strtotime($start);
    $endTime   = strtotime($end);

    if ($startTime === false || $endTime === false) {
        return ['start' => $start, 'end' => $end, 'days' => 0, 'error' => 'invalid'];
    }

    if ($endTime < $startTime) {
        [$start, $end]         = [$end, $start];
        [$startTime, $endTime] = [$endTime, $startTime];
    }

    return [
        'start' => $start,
        'end'   => $end,
        'days'  => (int) (($endTime - $startTime) / DAY_IN_SECONDS) + 1,
        'error' => '',
    ];
}

TestRunner::group('Quarter boundaries');

foreach ([
    ['2026-01-01', 'Q1 2026', '2026-01-01', '2026-03-31'],
    ['2026-03-31', 'Q1 2026', '2026-01-01', '2026-03-31'],
    ['2026-04-01', 'Q2 2026', '2026-04-01', '2026-06-30'],
    ['2026-08-06', 'Q3 2026', '2026-07-01', '2026-09-30'],
    ['2026-12-31', 'Q4 2026', '2026-10-01', '2026-12-31'],
    ['2027-01-01', 'Q1 2027', '2027-01-01', '2027-03-31'],
] as [$date, $label, $start, $end]) {
    $b = quarterBounds($date);

    TestRunner::same("{$date} falls in {$label}", $label, $b['label']);
    TestRunner::same("{$label} starts on {$start}", $start, $b['start']);
    TestRunner::same("{$label} ends on {$end}", $end, $b['end']);
}

TestRunner::group('Leap years');

// February in a leap year is the classic place a quarter end goes wrong.
TestRunner::same('leap year Q1 ends on 31 March', '2024-03-31', quarterBounds('2024-02-29')['end']);
TestRunner::same('a leap day resolves to Q1', 'Q1 2024', quarterBounds('2024-02-29')['label']);
TestRunner::same('non leap year Q1 ends on 31 March', '2026-03-31', quarterBounds('2026-02-28')['end']);

TestRunner::group('Previous quarter');

$q1 = quarterBounds('2026-01-15');
$previous = quarterBounds(gmdate('Y-m-d', strtotime($q1['start'] . ' -1 day')));

TestRunner::same('the quarter before Q1 2026 is Q4 2025', 'Q4 2025', $previous['label']);
TestRunner::same('it ends on 31 December', '2025-12-31', $previous['end']);

TestRunner::group('Period validation');

$normal = normaliseRange('2026-07-01', '2026-09-30');
TestRunner::same('a normal quarter counts 92 days', 92, $normal['days']);

$reversed = normaliseRange('2026-09-30', '2026-07-01');
TestRunner::same('a reversed range is swapped, not negative', 92, $reversed['days']);
TestRunner::same('swapped range starts on the earlier date', '2026-07-01', $reversed['start']);

$invalid = normaliseRange('not-a-date', 'also-not');
TestRunner::same('an unparseable range reports zero days', 0, $invalid['days']);
TestRunner::same('an unparseable range carries a reason', 'invalid', $invalid['error']);

$single = normaliseRange('2026-08-06', '2026-08-06');
TestRunner::same('a single day counts as one', 1, $single['days']);

TestRunner::group('Change calculation');

/**
 * Mirrors the comparison rule: no previous figure means no percentage, because
 * reporting a rise from nothing invents an improvement.
 */
function changeAgainst(int $current, ?int $previous): ?float
{
    if ($previous === null || $previous === 0) {
        return null;
    }

    return round((($current - $previous) / $previous) * 100, 1);
}

TestRunner::same('a rise is reported', 50.0, changeAgainst(150, 100));
TestRunner::same('a fall is reported', -25.0, changeAgainst(75, 100));
TestRunner::same('no change reads as zero', 0.0, changeAgainst(100, 100));
TestRunner::same('no previous figure produces no percentage', null, changeAgainst(100, null));
TestRunner::same('a previous figure of zero produces no percentage', null, changeAgainst(100, 0));

TestRunner::group('Data gaps');

function gapDays(int $expected, int $covered): int
{
    return max(0, $expected - $covered);
}

TestRunner::same('a full quarter has no gap', 0, gapDays(92, 92));
TestRunner::same('a partial quarter reports the gap', 62, gapDays(92, 30));
TestRunner::same('more data than days cannot make the gap negative', 0, gapDays(92, 100));

// ---------------------------------------------------------------------------
// Engagement, whichever shape the platform sends
// ---------------------------------------------------------------------------

TestRunner::group('Engagement parsing');

/**
 * Mirrors MetaProvider::engagementOf. Instagram media carry flat counts, Page
 * posts carry summary totals, and only Pages have shares.
 *
 * @param array<string,mixed> $item
 */
function engagementOf(array $item): int
{
    $likes = isset($item['like_count'])
        ? (int) $item['like_count']
        : (int) ($item['reactions']['summary']['total_count'] ?? 0);

    $comments = isset($item['comments_count'])
        ? (int) $item['comments_count']
        : (int) ($item['comments']['summary']['total_count'] ?? 0);

    return $likes + $comments + (int) ($item['shares']['count'] ?? 0);
}

TestRunner::same(
    'Instagram flat counts are added',
    205,
    engagementOf(['like_count' => 184, 'comments_count' => 21])
);

TestRunner::same(
    'Page summary totals are added, with shares',
    386,
    engagementOf([
        'reactions' => ['summary' => ['total_count' => 312]],
        'comments'  => ['summary' => ['total_count' => 47]],
        'shares'    => ['count' => 27],
    ])
);

TestRunner::same(
    'a post with no engagement reads as zero, not as missing',
    0,
    engagementOf(['id' => '123'])
);

TestRunner::same(
    'a flat count of zero is preferred over an absent summary',
    0,
    engagementOf(['like_count' => 0, 'comments_count' => 0])
);

TestRunner::same(
    'the two shapes never double count',
    100,
    engagementOf([
        'like_count' => 100,
        'reactions'  => ['summary' => ['total_count' => 999]],
    ])
);

// ---------------------------------------------------------------------------
// Per-share statistics, keyed by urn
// ---------------------------------------------------------------------------

TestRunner::group('LinkedIn share statistics');

/**
 * Mirrors LinkedInProvider::withStatistics. LinkedIn returns posts and their
 * figures from two separate calls, joined on the share urn.
 *
 * @param array<int,array<string,mixed>> $posts
 * @param array<int,array<string,mixed>> $elements
 * @return array<int,array<string,mixed>>
 */
function joinStatistics(array $posts, array $elements): array
{
    $byShare = [];

    foreach ($elements as $element) {
        $share = (string) ($element['share'] ?? '');

        if ($share === '') {
            continue;
        }

        $stats = $element['totalShareStatistics'] ?? [];

        $byShare[$share] = (int) ($stats['likeCount'] ?? 0)
            + (int) ($stats['commentCount'] ?? 0)
            + (int) ($stats['shareCount'] ?? 0);
    }

    foreach ($posts as $index => $post) {
        if (isset($byShare[$post['id']])) {
            $posts[$index]['engagement'] = $byShare[$post['id']];
        }
    }

    return $posts;
}

$joined = joinStatistics(
    [
        ['id' => 'urn:li:share:1', 'engagement' => 0],
        ['id' => 'urn:li:share:2', 'engagement' => 0],
    ],
    [
        ['share' => 'urn:li:share:1', 'totalShareStatistics' => ['likeCount' => 96, 'commentCount' => 14, 'shareCount' => 23]],
        ['share' => 'urn:li:share:2', 'totalShareStatistics' => ['likeCount' => 58, 'commentCount' => 6, 'shareCount' => 11]],
    ]
);

TestRunner::same('figures land on the right post', 133, $joined[0]['engagement']);
TestRunner::same('the second post gets its own figures', 75, $joined[1]['engagement']);

$partial = joinStatistics(
    [['id' => 'urn:li:share:1', 'engagement' => 0], ['id' => 'urn:li:share:missing', 'engagement' => 0]],
    [['share' => 'urn:li:share:1', 'totalShareStatistics' => ['likeCount' => 10]]]
);

TestRunner::same('a post with no statistics keeps zero', 0, $partial[1]['engagement']);
TestRunner::same('a post with statistics is still filled', 10, $partial[0]['engagement']);

TestRunner::same(
    'an element with no share urn is ignored rather than guessed at',
    0,
    joinStatistics(
        [['id' => 'urn:li:share:1', 'engagement' => 0]],
        [['totalShareStatistics' => ['likeCount' => 500]]]
    )[0]['engagement']
);

// ---------------------------------------------------------------------------
// Post ordering
// ---------------------------------------------------------------------------

TestRunner::group('Post ordering');

/**
 * @param array<int,array<string,mixed>> $posts
 * @return array<int,array<string,mixed>>
 */
function topPosts(array $posts, int $limit): array
{
    usort($posts, static fn(array $a, array $b): int => (int) $b['engagement'] <=> (int) $a['engagement']);

    return array_slice($posts, 0, $limit);
}

$ordered = topPosts([
    ['id' => 'a', 'engagement' => 104],
    ['id' => 'b', 'engagement' => 205],
    ['id' => 'c', 'engagement' => 64],
    ['id' => 'd', 'engagement' => 175],
], 3);

TestRunner::same('the best performing post comes first', 'b', $ordered[0]['id']);
TestRunner::same('ordering is strictly descending', 'd', $ordered[1]['id']);
TestRunner::same('the limit is applied after ordering', 3, count($ordered));
TestRunner::same('the weakest post is dropped, not the newest', 'a', $ordered[2]['id']);
TestRunner::same('an empty channel stays empty', 0, count(topPosts([], 10)));

// ---------------------------------------------------------------------------
// Token storage
// ---------------------------------------------------------------------------

TestRunner::group('Token encryption');

$available = function_exists('sodium_crypto_secretbox');

// libsodium has shipped in PHP core since 7.2, and this plugin requires 8.4.
// The guard exists so the suite reports the situation rather than fataling on
// an unusual build.
TestRunner::assert('libsodium is available', $available);

if ($available) {
    $key = hash('sha256', 'social-insights|test-auth-key', true);

    $encrypt = static function (string $value) use ($key): string {
        if ($value === '') {
            return $value;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'sisec1:' . base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $key));
    };

    $decrypt = static function (string $stored) use ($key): string {
        if ($stored === '' || !str_starts_with($stored, 'sisec1:')) {
            return $stored;
        }

        $raw = base64_decode(substr($stored, 7), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );

        return $plain === false ? '' : $plain;
    };

    $token  = 'EAABsbCS1iHgBO7ZC0000000000000000';
    $cipher = $encrypt($token);

    TestRunner::assert('the stored value is marked as encrypted', str_starts_with($cipher, 'sisec1:'));
    TestRunner::assert('the token is not readable in storage', !str_contains($cipher, 'EAABsbCS'));
    TestRunner::same('it decrypts back to the original', $token, $decrypt($cipher));

    TestRunner::assert(
        'encrypting twice gives different ciphertext',
        $encrypt($token) !== $encrypt($token)
    );

    TestRunner::same(
        'a value saved before encryption still reads',
        'plain-old-token',
        $decrypt('plain-old-token')
    );

    TestRunner::same('an empty token stays empty', '', $encrypt(''));

    TestRunner::same(
        'a token encrypted under different keys does not decrypt',
        '',
        (static function () use ($decrypt): string {
            $otherKey = hash('sha256', 'social-insights|rotated-salts', true);
            $nonce    = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher   = 'sisec1:' . base64_encode(
                $nonce . sodium_crypto_secretbox('secret', $nonce, $otherKey)
            );

            // Rotated salts must read as "not connected", not as a corrupt
            // token producing a baffling error from the API.
            return $decrypt($cipher);
        })()
    );

    TestRunner::same('a truncated stored value fails closed', '', $decrypt('sisec1:aGk='));
    TestRunner::same('rubbish in the stored value fails closed', '', $decrypt('sisec1:!!!not-base64!!!'));
}

TestRunner::group('Token input');

/**
 * A token pasted from a developer console arrives with whitespace more often
 * than not, and the API then rejects it with a message about the token being
 * invalid, which sends the administrator looking for the wrong problem.
 */
function cleanToken(string $submitted, string $stored): string
{
    $value = trim($submitted);

    return $value !== '' ? $value : $stored;
}

TestRunner::same('a trailing newline is removed', 'abc123', cleanToken("abc123\n", 'old'));
TestRunner::same('surrounding spaces are removed', 'abc123', cleanToken('  abc123  ', 'old'));
TestRunner::same('a blank field keeps the stored token', 'old', cleanToken('', 'old'));
TestRunner::same('a field of only whitespace keeps the stored token', 'old', cleanToken("   \n", 'old'));
TestRunner::same('a real value replaces the stored one', 'new', cleanToken('new', 'old'));

// ---------------------------------------------------------------------------
// Quarter selection
// ---------------------------------------------------------------------------

TestRunner::group('Quarter selection');

/**
 * Mirrors DashboardPage::requestedQuarter. Anything that is not a real quarter
 * falls back to the current one rather than being cleaned up and trusted.
 */
function quarterAnchor(string $value): ?string
{
    if (!preg_match('/^(\d{4})-Q([1-4])$/', $value, $matches)) {
        return null;
    }

    return sprintf('%04d-%02d-15', (int) $matches[1], ((int) $matches[2] - 1) * 3 + 1);
}

TestRunner::same('Q1 anchors in January', '2026-01-15', quarterAnchor('2026-Q1'));
TestRunner::same('Q3 anchors in July', '2026-07-15', quarterAnchor('2026-Q3'));
TestRunner::same('Q4 anchors in October', '2026-10-15', quarterAnchor('2026-Q4'));
TestRunner::same('a fifth quarter does not exist', null, quarterAnchor('2026-Q5'));
TestRunner::same('a zero quarter does not exist', null, quarterAnchor('2026-Q0'));
TestRunner::same('rubbish is refused', null, quarterAnchor('drop table'));
TestRunner::same('an empty value is refused', null, quarterAnchor(''));

// ---------------------------------------------------------------------------
// Follower chart scaling
// ---------------------------------------------------------------------------

TestRunner::group('Follower chart');

/**
 * The line is scaled between the lowest and highest value, not from zero.
 * Follower counts move by fractions of a percent, and a chart anchored at zero
 * draws that as a flat line, which says nothing happened about a quarter where
 * something did.
 *
 * @param array<int,int> $values
 * @return array<int,float>
 */
function chartRow(array $values, int $height = 90): array
{
    $low   = min($values);
    $high  = max($values);
    $range = max(1, $high - $low);

    return array_map(
        static fn(int $value): float => round($height - ((($value - $low) / $range) * ($height - 10)) - 5, 1),
        $values
    );
}

$line = chartRow([2100, 2140, 2184]);

TestRunner::same('the lowest point sits at the bottom', 85.0, $line[0]);
TestRunner::same('the highest point sits at the top', 5.0, $line[2]);
TestRunner::assert('the middle point sits between them', $line[1] < $line[0] && $line[1] > $line[2]);

TestRunner::assert(
    'a flat series does not divide by zero',
    (static function (): bool {
        foreach (chartRow([1500, 1500, 1500]) as $y) {
            if (!is_finite($y)) {
                return false;
            }
        }

        return true;
    })()
);

TestRunner::assert(
    'a small change is still visible',
    // Scaling from zero would put 2100 and 2184 within one pixel of each other.
    abs(chartRow([2100, 2184])[0] - chartRow([2100, 2184])[1]) > 50
);

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

TestRunner::group('CSV export');

/**
 * @param array<string,mixed> $report
 * @return array<int,array<int,string|int|float>>
 */
function exportRows(array $report): array
{
    $rows = [['Channel', 'Metric', 'Total', 'Best day', 'Days with data', 'Change vs previous quarter (%)']];

    foreach ($report['channels'] as $channel => $data) {
        if ($data['followers'] !== null) {
            $rows[] = [ucfirst((string) $channel), 'Followers at period end', $data['followers'], '', '', ''];
        }

        foreach ($data['metrics'] as $metric) {
            $rows[] = [
                ucfirst((string) $channel),
                $metric['label'],
                $metric['total'],
                $metric['peak'],
                $metric['days'],
                $report['comparison'][$channel][$metric['metric']]['change'] ?? '',
            ];
        }
    }

    return $rows;
}

$report = [
    'channels' => [
        'facebook' => [
            'followers' => 3427,
            'metrics'   => [
                ['metric' => 'reach', 'label' => 'Reach', 'total' => 12000, 'peak' => 900, 'days' => 34],
            ],
        ],
        'instagram' => [
            'followers' => null,
            'metrics'   => [
                ['metric' => 'reach', 'label' => 'Reach', 'total' => 8000, 'peak' => 600, 'days' => 34],
            ],
        ],
    ],
    'comparison' => ['facebook' => ['reach' => ['previous' => 9000, 'change' => 33.3]]],
];

$rows = exportRows($report);

TestRunner::same('header plus a row per figure', 4, count($rows));
TestRunner::same('followers are reported where known', 3427, $rows[1][2]);
TestRunner::same('the change column is filled where there is a comparison', 33.3, $rows[2][5]);
TestRunner::same('and left blank where there is none', '', $rows[3][5]);

TestRunner::assert(
    'a channel with no follower figure is not given one',
    // Two rows for facebook (followers + reach), one for instagram (reach).
    count(array_filter($rows, static fn(array $r): bool => $r[0] === 'Instagram')) === 1
);

exit(TestRunner::summary());
