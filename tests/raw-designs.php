<?php
/** Standalone artwork workflow on disposable WordPress, with WooCommerce excluded. */
$bootstrap = getenv('VTO_WP_LOAD') ?: (getenv('VTO_WP_ROOT') ? rtrim(getenv('VTO_WP_ROOT'), '/') . '/wp-load.php' : '');
if (!$bootstrap || !is_file($bootstrap)) { fwrite(STDERR, "Set VTO_WP_ROOT to disposable WordPress.\n"); exit(2); }
// Exclude WooCommerce for this process only: do not mutate installed plugins.
$GLOBALS['wp_filter']['option_active_plugins'][1][] = array('function' => static function ($plugins) {
    return array_values(array_filter((array) $plugins, static function ($plugin) { return $plugin !== 'woocommerce/woocommerce.php'; }));
}, 'accepted_args' => 1);
require $bootstrap;
if (!in_array(wp_get_environment_type(), array('local', 'development'), true)) { fwrite(STDERR, "Refusing non-development database.\n"); exit(2); }
$passed = 0;
function artwork_check($condition, string $message): void {
    global $passed;
    if (!$condition) { throw new RuntimeException($message); }
    $passed++; echo "PASS $message\n";
}
function artwork_fields(string $body, string $name): array {
    preg_match_all('/Content-Disposition: form-data; name="' . preg_quote($name, '/') . '"\r\n\r\n([^\r\n]*)\r\n/', $body, $matches);
    return $matches[1] ?? array();
}
$old_settings = get_option('vto_settings', null);
$old_user = get_current_user_id();
$attachment_id = 0; $oversized_attachment_id = 0; $created_jobs = array(); $paths = array(); $http_filter = null; $exit_code = 0;
try {
    artwork_check(!function_exists('wc_get_product'), 'Artwork workflow boots with WooCommerce completely absent');
    wp_set_current_user(1);
    $settings = VTO\Settings::defaults();
    artwork_check($settings['catalog_source'] === 'designs' && $settings['quality'] === 'low', 'Standalone artwork catalog and economical quality are the defaults');
    $settings['mode'] = 'avalai'; $settings['api_key'] = 'artwork-test-key-not-real';
    $settings['require_login'] = true; $settings['labels'] = array('choose_photo' => 'عکس تازه');
    $settings['product_ids'] = array(987654); // This list must not leak into the artwork catalog.
    $art = imagecreatetruecolor(240, 240);
    imagefill($art, 0, 0, imagecolorallocate($art, 163, 45, 80));
    imagefilledrectangle($art, 0, 0, 239, 19, imagecolorallocate($art, 250, 210, 100));
    imagefilledrectangle($art, 0, 220, 239, 239, imagecolorallocate($art, 250, 210, 100));
    imagefilledellipse($art, 120, 120, 80, 120, imagecolorallocate($art, 25, 95, 70));
    ob_start(); imagepng($art); $artwork = ob_get_clean(); imagedestroy($art);
    $upload = wp_upload_bits('vto-raw-artwork.png', null, $artwork);
    artwork_check(empty($upload['error']), 'Full raw scarf artwork is stored in the existing WordPress media library');
    $attachment_id = wp_insert_attachment(array('post_title' => 'طرح انار', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'), $upload['file']);
    update_attached_file($attachment_id, $upload['file']);
    $settings['banner_id'] = $attachment_id;
    update_option('vto_settings', $settings, false);
    $gallery_form = array('catalog_source' => 'designs', 'manual_products_present' => '1', 'manual_products' => array(
        array('id' => '', 'name' => 'طرح انار', 'image_id' => $attachment_id, 'active' => '1'),
        array('id' => 'hidden-art', 'name' => 'طرح پنهان', 'image_id' => $attachment_id, 'active' => '0'),
    ));
    $saved = VTO\Settings::sanitize($gallery_form);
    artwork_check($saved['api_key'] === $settings['api_key'] && $saved['mode'] === 'avalai' && $saved['model'] === $settings['model'] && $saved['quality'] === 'low', 'Saving only the design library preserves credentials, live mode and economical request profile');
    artwork_check($saved['require_login'] === true && $saved['banner_id'] === $attachment_id && $saved['labels'] === $settings['labels'] && $saved['product_ids'] === $settings['product_ids'], 'Design-only save preserves privacy, branding, labels and optional WooCommerce settings');
    update_option('vto_settings', $saved, false);
    $design_id = 'custom:item-' . $attachment_id;
    $catalog = VTO\Catalog::all();
    artwork_check(count($catalog) === 1 && $catalog[0]['id'] === $design_id && $catalog[0]['reference_type'] === 'artwork' && $catalog[0]['category'] === 'scarf', 'Catalog exposes only the active raw artwork with an automatically generated stable ID');
    artwork_check(VTO\Catalog::find('custom:hidden-art') === null && VTO\Catalog::find('wc:987654') === null, 'Inactive artworks and WooCommerce IDs are not selectable in standalone mode');
    $main_save = VTO\Settings::sanitize(array('title' => 'عنوان تازه', 'require_login' => '0'));
    artwork_check($main_save['manual_products'] === $saved['manual_products'] && $main_save['catalog_source'] === 'designs' && $main_save['require_login'] === false, 'Main settings save preserves artwork library and accepts explicit privacy checkbox changes');
    $reset_label = VTO\Settings::sanitize(array('labels' => array('choose_photo' => '')));
    artwork_check(!isset($reset_label['labels']['choose_photo']), 'Explicitly empty custom label restores its default instead of preserving stale text');
    $empty = VTO\Settings::sanitize(array('manual_products_present' => '1'));
    artwork_check($empty['manual_products'] === array() && $empty['api_key'] === $settings['api_key'], 'Explicitly removing the last artwork clears only the design library');
    $person = imagecreatetruecolor(128, 160); imagefill($person, 0, 0, imagecolorallocate($person, 85, 130, 190));
    ob_start(); imagepng($person); $input_bytes = ob_get_clean(); imagedestroy($person);
    $result_image = imagecreatetruecolor(128, 160); imagefill($result_image, 0, 0, imagecolorallocate($result_image, 35, 175, 90));
    ob_start(); imagepng($result_image); $result_bytes = ob_get_clean(); imagedestroy($result_image);
    $input = VTO\Storage::save($input_bytes); $paths[] = $input;
    VTO\Jobs::install();
    $created = VTO\Jobs::create($catalog[0], $input);
    artwork_check(!is_wp_error($created), 'A raw artwork try-on queues without any WooCommerce product');
    $created_jobs[] = $created['id'];
    $job = VTO\Jobs::by_id($created['id']);
    artwork_check($job['product_id'] === $design_id && (int) $job['product_image_id'] === $attachment_id && $job['quality'] === 'low', 'Job snapshots the artwork attachment and low quality before processing');
    $calls = 0; $body = '';
    $http_filter = static function ($pre, $args, $url) use (&$calls, &$body, $result_bytes) {
        if ($url !== VTO\Provider::ENDPOINT) { return new WP_Error('artwork_test_network_block', 'Unexpected network call blocked.'); }
        $calls++; $body = $args['body'];
        return array('headers' => array(), 'response' => array('code' => 200, 'message' => 'Mock'), 'body' => wp_json_encode(array('data' => array(array('b64_json' => base64_encode($result_bytes))))), 'cookies' => array());
    };
    add_filter('pre_http_request', $http_filter, 10, 3);
    // Changing catalog settings cannot change the snapshotted raw image role.
    $switched = $saved; $switched['catalog_source'] = 'woocommerce'; update_option('vto_settings', $switched, false);
    VTO\Jobs::process($created['id']);
    $complete = VTO\Jobs::by_id($created['id']);
    artwork_check($calls === 1 && $complete['status'] === 'completed', 'Artwork request completes with one intercepted generation even after switching catalog source');
    artwork_check(substr_count($body, 'name="image[]"') === 2 && !str_contains($body, 'product-context'), 'Raw artwork sends exactly customer plus artwork and no cropped or third context image');
    $person_at = strpos($body, 'filename="person.png"'); $design_at = strpos($body, 'filename="product-design.png"');
    artwork_check($person_at !== false && $design_at !== false && $person_at < $design_at && strpos($body, $input_bytes, $person_at) < $design_at && str_contains($body, "Content-Type: image/png\r\n\r\n" . $artwork . "\r\n"), 'Customer is first and the full unmodified raw artwork bytes are second, including all borders');
    artwork_check(artwork_fields($body, 'quality') === array('low') && artwork_fields($body, 'size') === array('1024x1024') && artwork_fields($body, 'n') === array('1') && artwork_fields($body, 'complexity') === array(), 'Raw artwork keeps low quality, 1024 square, one output and no complexity override');
    artwork_check(str_contains($body, 'RAW FLAT SCARF ARTWORK') && str_contains($body, 'exactly TWO reference images') && str_contains($body, 'fully replace the scarf currently covering the head, neck and shoulders') && str_contains($body, 'Do not paste a flat square'), 'Fixed prompt maps the full raw design onto a new scarf while preserving the customer identity');
    $before = $calls; VTO\Jobs::process($created['id']);
    artwork_check($calls === $before, 'A completed artwork job cannot accidentally submit a second paid generation');
    $bad = $job; $bad['product_id'] = 'custom:bad/id';
    artwork_check(is_wp_error(VTO\Provider::generate($bad)) && $calls === $before, 'Malformed artwork identifier is rejected before any generation request');
    $large = imagecreatetruecolor(1600, 800);
    imagefill($large, 0, 0, imagecolorallocate($large, 250, 250, 250));
    imagefilledrectangle($large, 0, 0, 1599, 49, imagecolorallocate($large, 220, 20, 30));
    imagefilledrectangle($large, 0, 750, 1599, 799, imagecolorallocate($large, 25, 175, 50));
    imagefilledrectangle($large, 0, 50, 49, 749, imagecolorallocate($large, 20, 45, 220));
    imagefilledrectangle($large, 1550, 50, 1599, 749, imagecolorallocate($large, 225, 180, 20));
    ob_start(); imagepng($large); $large_bytes = ob_get_clean(); imagedestroy($large);
    $large_upload = wp_upload_bits('vto-oversized-artwork.png', null, $large_bytes);
    $oversized_attachment_id = wp_insert_attachment(array('post_title' => 'Oversized artwork', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'), $large_upload['file']);
    update_attached_file($oversized_attachment_id, $large_upload['file']);
    $oversized_job = $job; unset($oversized_job['id']); $oversized_job['product_image_id'] = $oversized_attachment_id;
    $oversized_result = VTO\Provider::generate($oversized_job);
    artwork_check(!is_wp_error($oversized_result) && $calls === $before + 1 && substr_count($body, 'name="image[]"') === 2 && !str_contains($body, 'product-context'), 'Oversized artwork still uses exactly two image references without a hidden full-size context copy');
    preg_match('/filename="product-design.jpg"\r\nContent-Type: image\/jpeg\r\n\r\n(.*?)\r\n--vto-/s', $body, $large_match);
    $bounded_info = isset($large_match[1]) ? getimagesizefromstring($large_match[1]) : false;
    artwork_check($bounded_info && $bounded_info[0] === 1280 && $bounded_info[1] === 640, 'Oversized artwork is reduced to a 1280px longest side with its full aspect ratio');
    $bounded = imagecreatefromstring($large_match[1]);
    $top = imagecolorat($bounded, 640, 15); $bottom = imagecolorat($bounded, 640, 625);
    $left = imagecolorat($bounded, 15, 320); $right = imagecolorat($bounded, 1265, 320);
    $channels = static function ($color) { return array(($color >> 16) & 255, ($color >> 8) & 255, $color & 255); };
    $top = $channels($top); $bottom = $channels($bottom); $left = $channels($left); $right = $channels($right);
    artwork_check($top[0] > 190 && $top[1] < 60 && $bottom[1] > 140 && $bottom[0] < 60 && $left[2] > 180 && $right[0] > 190 && $right[1] > 150, 'Full-frame artwork reduction retains all four colored borders without cropping');
    imagedestroy($bounded);
    artwork_check(artwork_fields($body, 'quality') === array('low') && artwork_fields($body, 'size') === array('1024x1024') && artwork_fields($body, 'n') === array('1'), 'Oversized artwork optimization preserves the economical generation profile');
    echo "\n$passed raw-artwork assertions passed. WooCommerce was not loaded and every HTTP call was intercepted; no paid API request occurred.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL); $exit_code = 1;
} finally {
    if ($http_filter) { remove_filter('pre_http_request', $http_filter, 10); }
    foreach ($created_jobs as $id) { VTO\Jobs::delete((int) $id); }
    foreach ($paths as $path) { if (is_string($path)) { VTO\Storage::delete($path); } }
    if ($attachment_id) { wp_delete_attachment($attachment_id, true); }
    if ($oversized_attachment_id) { wp_delete_attachment($oversized_attachment_id, true); }
    if ($old_settings === null) { delete_option('vto_settings'); } else { update_option('vto_settings', $old_settings, false); }
    wp_set_current_user($old_user);
}
exit($exit_code);
