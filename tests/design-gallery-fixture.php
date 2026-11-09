<?php
/** Disposable local WordPress fixtures for the browser gallery test. */
$root = getenv('VTO_WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('Set VTO_WP_ROOT.'); }
require $root . '/wp-load.php';
if (!in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    throw new RuntimeException('Refusing non-development database.');
}
$request = json_decode(stream_get_contents(STDIN), true) ?: array();
$action = $argv[1] ?? '';
if ('setup' === $action) {
    $state = array(
        'old_settings' => get_option('vto_settings', null),
        'old_plugins' => get_option('active_plugins', array()),
        'attachment_ids' => array(), 'page_id' => 0,
    );
    $names = array('باغ ایرانی', 'هندسی سرمه‌ای', 'گل‌های اناری', 'طرح غیرفعال');
    $palettes = array(array(39, 91, 80), array(34, 50, 85), array(137, 49, 72), array(115, 93, 69));
    foreach ($names as $index => $name) {
        $image = imagecreatetruecolor(720, 720);
        $base = imagecolorallocate($image, ...$palettes[$index]);
        $gold = imagecolorallocate($image, 231, 194, 126);
        $cream = imagecolorallocate($image, 250, 239, 217);
        imagefill($image, 0, 0, $base);
        for ($border = 12; $border < 44; $border += 6) {
            imagerectangle($image, $border, $border, 719 - $border, 719 - $border, $gold);
        }
        for ($x = 100; $x < 720; $x += 130) {
            for ($y = 100; $y < 720; $y += 130) {
                imagefilledellipse($image, $x, $y, 18, 60, $cream);
                imagefilledellipse($image, $x, $y, 60, 18, $cream);
                imagefilledellipse($image, $x, $y, 12, 12, $gold);
                imageline($image, $x - 35, $y - 35, $x + 35, $y + 35, $gold);
            }
        }
        ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
        $uploaded = wp_upload_bits('vto-gallery-browser-' . $index . '.png', null, $png);
        if (!empty($uploaded['error'])) { throw new RuntimeException('Could not create artwork fixture.'); }
        $id = wp_insert_attachment(array('post_title' => $name, 'post_status' => 'inherit', 'post_mime_type' => 'image/png'), $uploaded['file']);
        update_attached_file($id, $uploaded['file']);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $uploaded['file']));
        $state['attachment_ids'][] = $id;
    }
    $state['page_id'] = wp_insert_post(array('post_title' => 'طرح دلخواهت را روی خودت ببین', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[virtual_tryon]'));
    $settings = VTO\Settings::defaults();
    $settings['catalog_source'] = 'designs';
    $settings['mode'] = 'demo';
    $settings['api_key'] = 'browser-test-no-real-credential';
    $settings['quality'] = 'low';
    $settings['require_login'] = false;
    $settings['retention_hours'] = 18;
    $settings['global_rate_limit'] = 1000;
    $settings['rate_limit'] = 50;
    $settings['manual_products'] = array(
        array('id' => 'browser-garden', 'name' => $names[0], 'image_id' => $state['attachment_ids'][0], 'active' => true),
        array('id' => 'browser-navy', 'name' => $names[1], 'image_id' => $state['attachment_ids'][1], 'active' => true),
        array('id' => 'browser-disabled', 'name' => $names[3], 'image_id' => $state['attachment_ids'][3], 'active' => false),
    );
    update_option('vto_settings', $settings, false);
    // The next web/CLI request boots WordPress with no WooCommerce code loaded.
    update_option('active_plugins', array_values(array_filter($state['old_plugins'], static function ($plugin) { return $plugin !== 'woocommerce/woocommerce.php'; })));
    $expiry = time() + HOUR_IN_SECONDS;
    $state['session_token'] = WP_Session_Tokens::get_instance(1)->create($expiry);
    $state['cookies'] = array();
    foreach (array(array(LOGGED_IN_COOKIE, 'logged_in'), array(AUTH_COOKIE, 'auth')) as $cookie) {
        $state['cookies'][] = array('name' => $cookie[0], 'value' => wp_generate_auth_cookie(1, $expiry, $cookie[1], $state['session_token']), 'url' => home_url('/'), 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax', 'expires' => $expiry);
    }
    $state['base'] = home_url('/');
    $state['page_url'] = add_query_arg('page_id', $state['page_id'], home_url('/'));
    echo wp_json_encode($state);
} elseif ('verify' === $action) {
    $s = VTO\Settings::get();
    echo wp_json_encode(array('woo_loaded' => function_exists('wc_get_product'), 'catalog' => VTO\Catalog::all(), 'source' => $s['catalog_source'], 'mode' => $s['mode'], 'quality' => $s['quality'], 'credential_preserved' => $s['api_key'] === 'browser-test-no-real-credential', 'retention_hours' => $s['retention_hours'], 'design_count' => count($s['manual_products'])));
} elseif ('process' === $action) {
    $job = VTO\Jobs::by_token((string) ($request['token'] ?? ''));
    if (!$job || $job['mode'] !== 'demo') { throw new RuntimeException('Only a demo browser fixture may be processed.'); }
    add_filter('pre_http_request', static function () { throw new RuntimeException('External HTTP forbidden in browser test.'); }, PHP_INT_MAX);
    VTO\Jobs::process($job['id']);
    echo wp_json_encode(array('status' => VTO\Jobs::by_id($job['id'])['status']));
} elseif ('cleanup' === $action) {
    foreach ((array) ($request['tokens'] ?? array()) as $token) {
        $job = VTO\Jobs::by_token((string) $token);
        if ($job) { VTO\Jobs::delete($job['id']); }
    }
    foreach ((array) ($request['attachment_ids'] ?? array()) as $id) { wp_delete_attachment($id, true); }
    if (!empty($request['page_id'])) { wp_delete_post($request['page_id'], true); }
    if (isset($request['old_settings'])) { update_option('vto_settings', $request['old_settings'], false); } else { delete_option('vto_settings'); }
    update_option('active_plugins', $request['old_plugins']);
    WP_Session_Tokens::get_instance(1)->destroy($request['session_token']);
    echo 'true';
} else { throw new RuntimeException('Unknown fixture action.'); }
