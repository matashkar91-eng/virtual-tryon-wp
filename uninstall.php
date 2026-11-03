<?php
/** Removing the plugin deletes its local personal images and request metadata. */
defined('WP_UNINSTALL_PLUGIN') || exit;
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-storage.php';
global $wpdb;
$vto_table = $wpdb->prefix . 'vto_jobs';
$vto_rows = $wpdb->get_results("SELECT * FROM {$vto_table}", ARRAY_A);
foreach ((array) $vto_rows as $vto_row) {
    \VTO\Storage::delete($vto_row['input_path']);
    \VTO\Storage::delete($vto_row['output_path']);
    \VTO\Storage::delete((string) ($vto_row['reference_path'] ?? ''));
}
$vto_dir = \VTO\Storage::directory();
if (!is_wp_error($vto_dir) && count(scandir($vto_dir)) === 2) { @rmdir($vto_dir); }
$wpdb->query("DROP TABLE IF EXISTS {$vto_table}");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}vto_rates");
delete_option('vto_settings');
delete_option('vto_db_version');
wp_clear_scheduled_hook('vto_maintenance');
wp_unschedule_hook('vto_process_job');
