<?php
/**
 * LinkedIn organisation pages through the Marketing API.
 *
 * LinkedIn measures differently from Meta: it reports impressions and a
 * separate engagement rate per share, and it has no equivalent of Instagram
 * reach. Rather than force it into a shared shape, this provider reports what
 * LinkedIn actually returns and the report shows it beside the others.
 *
 * @package SocialInsights
 */

declare(strict_types=1);

namespace ClarityWeb\SocialInsights\Provider;

defined('ABSPATH') || exit;

final class LinkedInProvider implements SocialProvider
{
    private const BASE    = 'https://api.linkedin.com';
    private const VERSION = '202411';

    public function __construct(
        private string $organisationId,
        private string $token,
        private string $endpoint = '',
    ) {
        $this->endpoint = $this->endpoint !== '' ? rtrim($this->endpoint, '/') : self::BASE;
    }

    public function key(): string
    {
        return 'linkedin';
    }

    public function label(): string
    {
        return 'LinkedIn';
    }

    public function isConfigured(): bool
    {
        return $this->organisationId !== '' && $this->token !== '';
    }

    public function followers(): int
    {
        $data = $this->get(
            '/rest/networkSizes/urn:li:organization:' . rawurlencode($this->organisationId),
            ['edgeType' => 'CompanyFollowedByMember']
        );

        return (int) ($data['firstDegreeSize'] ?? 0);
    }

    public function metrics(string $since, string $until): array
    {
        $data = $this->get('/rest/organizationalEntityShareStatistics', [
            'q'                          => 'organizationalEntity',
            'organizationalEntity'       => 'urn:li:organization:' . $this->organisationId,
            'timeIntervals.timeRange.start' => strtotime($since) * 1000,
            'timeIntervals.timeRange.end'   => strtotime($until) * 1000,
            'timeIntervals.timeGranularityType' => 'DAY',
        ]);

        $impressions = 0;
        $clicks      = 0;
        $reactions   = 0;

        foreach ($data['elements'] ?? [] as $element) {
            $stats = $element['totalShareStatistics'] ?? [];

            $impressions += (int) ($stats['impressionCount'] ?? 0);
            $clicks      += (int) ($stats['clickCount'] ?? 0);
            $reactions   += (int) ($stats['likeCount'] ?? 0)
                + (int) ($stats['commentCount'] ?? 0)
                + (int) ($stats['shareCount'] ?? 0);
        }

        return [
            'impressionCount' => [
                'label' => 'Impressions',
                'value' => $impressions,
                'note'  => 'Times a post was displayed',
            ],
            'clickCount' => [
                'label' => 'Clicks',
                'value' => $clicks,
                'note'  => 'Clicks on posts',
            ],
            'reactions' => [
                'label' => 'Reactions',
                'value' => $reactions,
                'note'  => 'Likes, comments and reposts combined',
            ],
        ];
    }

    public function posts(string $since, string $until): array
    {
        $data = $this->get('/rest/posts', [
            'q'      => 'author',
            'author' => 'urn:li:organization:' . $this->organisationId,
            'count'  => 50,
        ]);

        $posts = [];

        foreach ($data['elements'] ?? [] as $item) {
            $created = (int) ($item['createdAt'] ?? 0);

            // LinkedIn's post query does not take a date range, so the window
            // is applied here rather than pretending the API did it.
            if ($created > 0) {
                $seconds = (int) ($created / 1000);

                if ($seconds < strtotime($since) || $seconds > strtotime($until)) {
                    continue;
                }
            }

            $id = (string) ($item['id'] ?? '');

            $posts[] = [
                'id'        => $id,
                'published' => $created > 0 ? gmdate('Y-m-d H:i:s', (int) ($created / 1000)) : '',
                'text'      => mb_substr((string) ($item['commentary'] ?? ''), 0, 160),
                // The permalink is not returned by the posts query. This is the
                // documented format for a share urn and is stable, so it is
                // built rather than left blank.
                'url'         => $id !== '' ? 'https://www.linkedin.com/feed/update/' . $id : '',
                'impressions' => 0,
                'engagement'  => 0,
            ];
        }

        return self::withStatistics($posts);
    }

    /**
     * Fills in per-post figures from the share statistics endpoint.
     *
     * LinkedIn splits this across two calls: the posts query returns the posts
     * and no numbers, and the statistics query returns numbers keyed by share
     * urn. Without the second call every row reads zero, which looks like a
     * broken integration rather than the API's shape.
     *
     * A failure here degrades to the posts without their figures. Losing the
     * engagement column is a smaller loss than losing the list.
     *
     * @param array<int,array<string,mixed>> $posts
     * @return array<int,array<string,mixed>>
     */
    private function withStatistics(array $posts): array
    {
        if ($posts === []) {
            return $posts;
        }

        $query = [
            'q'                   => 'organizationalEntity',
            'organizationalEntity' => 'urn:li:organization:' . $this->organisationId,
        ];

        foreach (array_values($posts) as $index => $post) {
            $query["shares[{$index}]"] = $post['id'];
        }

        try {
            $data = $this->get('/rest/organizationalEntityShareStatistics', $query);
        } catch (ProviderUnavailable) {
            return $posts;
        }

        $byShare = [];

        foreach ($data['elements'] ?? [] as $element) {
            $share = (string) ($element['share'] ?? '');

            if ($share === '') {
                continue;
            }

            $stats = $element['totalShareStatistics'] ?? [];

            $byShare[$share] = [
                'impressions' => (int) ($stats['impressionCount'] ?? 0),
                'engagement'  => (int) ($stats['likeCount'] ?? 0)
                    + (int) ($stats['commentCount'] ?? 0)
                    + (int) ($stats['shareCount'] ?? 0),
            ];
        }

        foreach ($posts as $index => $post) {
            $figures = $byShare[$post['id']] ?? null;

            if ($figures !== null) {
                $posts[$index]['impressions'] = $figures['impressions'];
                $posts[$index]['engagement']  = $figures['engagement'];
            }
        }

        return $posts;
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'No organisation id or token configured.'];
        }

        try {
            $followers = $this->followers();
        } catch (ProviderUnavailable $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }

        return ['ok' => true, 'message' => sprintf('Connected. %s followers.', number_format($followers))];
    }

    /**
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     *
     * @throws ProviderUnavailable
     */
    private function get(string $path, array $query): array
    {
        if (!$this->isConfigured()) {
            throw new ProviderUnavailable('This channel is not configured.');
        }

        $response = wp_remote_get(add_query_arg($query, $this->endpoint . $path), [
            'timeout' => 25,
            'headers' => [
                'Authorization'             => 'Bearer ' . $this->token,
                'LinkedIn-Version'          => self::VERSION,
                'X-Restli-Protocol-Version' => '2.0.0',
            ],
        ]);

        if (is_wp_error($response)) {
            throw new ProviderUnavailable($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code === 401) {
            throw new ProviderUnavailable('LinkedIn rejected the token. Access tokens expire and need renewing.');
        }

        if ($code !== 200) {
            throw new ProviderUnavailable((string) ($body['message'] ?? sprintf('HTTP %d', $code)));
        }

        return is_array($body) ? $body : [];
    }
}
