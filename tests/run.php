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

exit(TestRunner::summary());
