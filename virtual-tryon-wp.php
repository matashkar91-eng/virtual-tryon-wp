<?php
/**
 * Plugin Name: Virtual Try-On Studio
 * Description: پرو مجازی قابل تنظیم برای فروشگاه‌های ووکامرس، با صف پردازش، پیگیری خصوصی و اتصال به AvalAI.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Virtual Try-On Studio
 * Text Domain: virtual-tryon-wp
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
define('VTO_VERSION', '0.2.0');
define('VTO_DIR', plugin_dir_path(__FILE__));
define('VTO_URL', plugin_dir_url(__FILE__));
foreach (['settings', 'catalog', 'storage', 'provider-result', 'provider', 'jobs', 'rest', 'frontend', 'admin'] as $vto_file) {
    require_once VTO_DIR . 'includes/class-' . $vto_file . '.php';
}
register_activation_hook(__FILE__, ['VTO\\Jobs', 'install']);
register_deactivation_hook(__FILE__, ['VTO\\Jobs', 'deactivate']);
add_action('plugins_loaded', static function () {
    \VTO\Jobs::init();
    \VTO\Rest::init();
    \VTO\Frontend::init();
    \VTO\Admin::init();
});
