<?php
/**
 * Encryption for the access tokens.
 *
 * A social API token is a password to a public body's official accounts. It
 * can post, delete, and read private message metadata. Stored as plain text in
 * wp_options it travels into every database dump, every backup, every staging
 * copy handed to a contractor, and every export a developer takes to debug
 * something unrelated. None of those places is where it belongs.
 *
 * Encrypted at rest, a leaked dump is inert without the site's own keys, which
 * live in wp-config and are not in the database. That is a genuine improvement
 * rather than security theatre: it separates the secret from the copy of the
 * data that actually goes wandering.
 *
 * libsodium, so no dependency: it has been in PHP core since 7.2, and this
 * plugin already refuses to add packages it would have to keep patched for
 * eighteen months.
 *
 * The key is derived from AUTH_KEY. Rotating those constants makes stored
 * tokens unreadable, which is the correct behaviour for a credential store,
 * and the settings screen says so plainly rather than showing an empty field
 * and letting an administrator wonder what happened.
 *
 * @package SocialInsights
 */

declare(strict_types=1);

namespace ClarityWeb\SocialInsights;

defined('ABSPATH') || exit;

final class Secret
{
    /** Marks a value as encrypted by this class, and by which scheme. */
    private const PREFIX = 'sisec1:';

    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox');
    }

    /**
     * Encrypts a value for storage.
     *
     * Returns the value unchanged when encryption is unavailable. Refusing to
     * save would be worse: the plugin would be unusable on that host, and the
     * administrator would have no idea why. The settings screen reports the
     * situation instead, so the choice is visible rather than silent.
     */
    public static function encrypt(string $value): string
    {
        if ($value === '' || !self::available()) {
            return $value;
        }

        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($value, $nonce, self::key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * Decrypts a stored value.
     *
     * A value without the marker is returned as it is. That is what makes this
     * safe to introduce on a site that already has tokens saved: they keep
     * working, and each one becomes encrypted the next time it is saved.
     */
    public static function decrypt(string $stored): string
    {
        if ($stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }

        if (!self::available()) {
            return '';
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        // False means the key no longer matches, almost always because the
        // site's salts were rotated. Returning an empty string surfaces as
        // "not connected", which is true, rather than as a corrupt token that
        // produces a baffling error from the API.
        return $plain === false ? '' : $plain;
    }

    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    private static function key(): string
    {
        $material = defined('AUTH_KEY') ? (string) constant('AUTH_KEY') : '';

        if ($material === '') {
            $material = wp_salt('auth');
        }

        // A hash of the salt rather than the salt itself, so the exact length
        // secretbox requires is guaranteed whatever the constant contains.
        return hash('sha256', 'social-insights|' . $material, true);
    }
}
