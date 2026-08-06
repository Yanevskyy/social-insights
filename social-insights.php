<?php
/**
 * Plugin Name:       Social Insights
 * Plugin URI:        https://github.com/Yanevskyy/social-insights
 * Description:       Collects reach and engagement from Instagram, Facebook and LinkedIn into WordPress, and builds the quarterly report.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            ClarityWeb
 * Author URI:        https://clarityweb.ie
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-insights
 *
 * @package SocialInsights
 */

declare(strict_types=1);

namespace ClarityWeb\SocialInsights;

if (!defined('ABSPATH')) {
    exit;
}

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;

define('SI_DIR', plugin_dir_path(__FILE__));
define('SI_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = SI_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, [Plugin::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
