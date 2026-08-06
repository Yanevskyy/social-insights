<?php
/**
 * Contract for a social channel.
 *
 * Each platform reports different things under similar names, so the contract
 * deliberately does not pretend they are the same. A provider returns what its
 * platform actually measures, labelled with the platform's own term, and the
 * report shows them side by side rather than adding them into a single figure
 * that would look impressive and mean nothing.
 *
 * @package SocialInsights
 */

declare(strict_types=1);

namespace ClarityWeb\SocialInsights\Provider;

defined('ABSPATH') || exit;

interface SocialProvider
{
    /**
     * Machine name: instagram, facebook, linkedin.
     */
    public function key(): string;

    /**
     * Name shown to a human.
     */
    public function label(): string;

    /**
     * Whether this channel has credentials configured.
     */
    public function isConfigured(): bool;

    /**
     * Audience size right now.
     *
     * @throws ProviderUnavailable
     */
    public function followers(): int;

    /**
     * Channel level metrics for a date range.
     *
     * Keys vary by platform on purpose. Each entry carries the platform's own
     * metric name so a report can say "Instagram reach" and "Facebook
     * impressions" rather than inventing a shared word for two different
     * measurements.
     *
     * @return array<string,array{label:string,value:int,note:string}>
     *
     * @throws ProviderUnavailable
     */
    public function metrics(string $since, string $until): array;

    /**
     * Individual posts in the range, most recent first.
     *
     * @return array<int,array{id:string,published:string,text:string,url:string,impressions:int,engagement:int}>
     *
     * @throws ProviderUnavailable
     */
    public function posts(string $since, string $until): array;

    /**
     * Checks credentials without pulling a full report.
     *
     * @return array{ok:bool,message:string}
     */
    public function testConnection(): array;
}
