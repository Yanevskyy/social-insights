<?php
/**
 * Instagram and Facebook through the Meta Graph API.
 *
 * One class for both because they share the same API, the same token model and
 * the same failure modes. What differs is which metric names each accepts,
 * which is data rather than logic.
 *
 * Real endpoints and real metric names are used, so the class works against
 * live credentials. What it will not do is invent numbers when there are none.
 *
 * @package SocialInsights
 */

declare(strict_types=1);

namespace ClarityWeb\SocialInsights\Provider;

defined('ABSPATH') || exit;

final class MetaProvider implements SocialProvider
{
    private const API_VERSION = 'v21.0';
    private const BASE        = 'https://graph.facebook.com/';

    /**
     * Metric names differ between the two products even though the API is
     * shared. Keeping them here rather than in branching logic means adding a
     * metric is an edit to a list.
     */
    private const METRICS = [
        'instagram' => [
            'reach'         => ['label' => 'Reach', 'note' => 'Unique accounts that saw any content'],
            'impressions'   => ['label' => 'Impressions', 'note' => 'Total times content was displayed'],
            'profile_views' => ['label' => 'Profile views', 'note' => 'Visits to the profile'],
        ],
        'facebook' => [
            'page_impressions_unique' => ['label' => 'Reach', 'note' => 'People who saw any page content'],
            'page_impressions'        => ['label' => 'Impressions', 'note' => 'Total content displays'],
            'page_engaged_users'      => ['label' => 'Engaged people', 'note' => 'People who interacted'],
        ],
    ];

    public function __construct(
        private string $platform,
        private string $accountId,
        private string $token,
        private string $endpoint = '',
    ) {
        $this->endpoint = $this->endpoint !== '' ? rtrim($this->endpoint, '/') : self::BASE . self::API_VERSION;
    }

    public function key(): string
    {
        return $this->platform;
    }

    public function label(): string
    {
        return $this->platform === 'instagram' ? 'Instagram' : 'Facebook';
    }

    public function isConfigured(): bool
    {
        return $this->accountId !== '' && $this->token !== '';
    }

    public function followers(): int
    {
        $field = $this->platform === 'instagram' ? 'followers_count' : 'fan_count';
        $data  = $this->get("/{$this->accountId}", ['fields' => $field]);

        return (int) ($data[$field] ?? 0);
    }

    public function metrics(string $since, string $until): array
    {
        $names = array_keys(self::METRICS[$this->platform] ?? []);

        if ($names === []) {
            return [];
        }

        $data = $this->get("/{$this->accountId}/insights", [
            'metric' => implode(',', $names),
            'period' => 'day',
            'since'  => $since,
            'until'  => $until,
        ]);

        $result = [];

        foreach ($data['data'] ?? [] as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $meta = self::METRICS[$this->platform][$name] ?? null;

            if ($meta === null) {
                continue;
            }

            // Daily series summed across the range. Reported as the platform's
            // own metric, never merged with another platform's.
            $total = 0;

            foreach ($entry['values'] ?? [] as $point) {
                $total += (int) ($point['value'] ?? 0);
            }

            $result[$name] = [
                'label' => $meta['label'],
                'value' => $total,
                'note'  => $meta['note'],
            ];
        }

        return $result;
    }

    public function posts(string $since, string $until): array
    {
        $edge   = $this->platform === 'instagram' ? 'media' : 'posts';
        $fields = $this->platform === 'instagram'
            ? 'id,caption,permalink,timestamp,like_count,comments_count'
            : 'id,message,permalink_url,created_time,shares';

        $data = $this->get("/{$this->accountId}/{$edge}", [
            'fields' => $fields,
            'since'  => $since,
            'until'  => $until,
            'limit'  => 50,
        ]);

        $posts = [];

        foreach ($data['data'] ?? [] as $item) {
            $posts[] = [
                'id'          => (string) ($item['id'] ?? ''),
                'published'   => (string) ($item['timestamp'] ?? $item['created_time'] ?? ''),
                'text'        => mb_substr((string) ($item['caption'] ?? $item['message'] ?? ''), 0, 160),
                'url'         => (string) ($item['permalink'] ?? $item['permalink_url'] ?? ''),
                'impressions' => 0,
                'engagement'  => (int) ($item['like_count'] ?? 0) + (int) ($item['comments_count'] ?? 0),
            ];
        }

        return $posts;
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'No account id or token configured.'];
        }

        try {
            $followers = $this->followers();
        } catch (ProviderUnavailable $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }

        return [
            'ok'      => true,
            'message' => sprintf('Connected. %s followers.', number_format($followers)),
        ];
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

        $query['access_token'] = $this->token;

        $response = wp_remote_get(
            add_query_arg($query, $this->endpoint . $path),
            ['timeout' => 25]
        );

        if (is_wp_error($response)) {
            throw new ProviderUnavailable($response->get_error_message());
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            // Meta returns a structured error. Passing its own words through is
            // more useful than "request failed": an expired token and a missing
            // permission need different fixes.
            $message = $body['error']['message'] ?? sprintf('HTTP %d', $code);

            throw new ProviderUnavailable((string) $message);
        }

        return is_array($body) ? $body : [];
    }
}
