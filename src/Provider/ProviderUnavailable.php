<?php
/**
 * Raised when a social platform cannot answer.
 *
 * Separate from "the channel had no activity". A quarter with genuinely zero
 * posts and a quarter where the API refused our token look identical in a
 * number, and reporting the second as the first would put a false figure in
 * front of a public body.
 *
 * @package SocialInsights
 */

declare(strict_types=1);

namespace ClarityWeb\SocialInsights\Provider;

defined('ABSPATH') || exit;

final class ProviderUnavailable extends \RuntimeException
{
}
