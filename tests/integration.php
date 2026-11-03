<?php
/**
 * Integration checks against a real disposable WordPress + WooCommerce install.
 * VTO_WP_LOAD=/absolute/path/to/wp-load.php php tests/integration.php
 * Refuses production/staging. Never run against a merchant's live database.
 */
$bootstrap = getenv('VTO_WP_LOAD') ?: (getenv('VTO_WP_ROOT') ? rtrim(getenv('VTO_WP_ROOT'), '/').'/wp-load.php' : '');
if (!$bootstrap || !is_file($bootstrap)) {
    fwrite(STDERR, "Set VTO_WP_LOAD to a disposable WordPress wp-load.php.\n");
    exit(2);
}
require $bootstrap;
if (!in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    fwrite(STDERR, "Tests require WP_ENVIRONMENT_TYPE=local or development.\n");
    exit(2);
}
if (!class_exists('VTO\\Jobs') || !class_exists('WC_Product_Simple')) {
    fwrite(STDERR, "Activate Virtual Try-On Studio and WooCommerce first.\n");
    exit(2);
}
$passed = 0;
function check($condition, $message) {
    global $passed;
    if (!$condition) { throw new RuntimeException($message); }
    $passed++;
    echo "PASS $message\n";
}
$old_settings = get_option('vto_settings', null);
$old_user = get_current_user_id();
$product_id = 0;
$attachment_id = 0;
$created_jobs = [];
$temporary_paths = [];
$http_filter = null;
$exit_code = 0;
try {
    wp_set_current_user(1);
    $settings = VTO\Settings::defaults();
    $settings['mode'] = 'demo';
    $settings['rate_limit'] = 2;
    $settings['global_rate_limit'] = 1000;
    update_option('vto_settings', $settings, false);
    VTO\Jobs::install();
    $image = imagecreatetruecolor(128, 128);
    imagefill($image, 0, 0, imagecolorallocate($image, 90, 100, 155));
    ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
    $uploaded = wp_upload_bits('vto-integration-product.png', null, $png);
    check(empty($uploaded['error']), 'Product fixture image created');
    $attachment_id = wp_insert_attachment(['post_title'=>'VTO test reference', 'post_mime_type'=>'image/png', 'post_status'=>'inherit'], $uploaded['file']);
    update_attached_file($attachment_id, $uploaded['file']);
    $product = new WC_Product_Simple();
    $product->set_name('روسری آزمایشی');
    $product->set_status('publish');
    $product->set_regular_price('100');
    $product->set_image_id($attachment_id);
    $product_id = $product->save();
    $settings['product_ids'] = [$product_id];
    update_option('vto_settings', $settings, false);
    $catalog = VTO\Catalog::all();
    check(count($catalog) === 1 && $catalog[0]['id'] === 'wc:'.$product_id, 'Catalog exposes only allowed WooCommerce products');
    check(VTO\Catalog::find('wc:9999999') === null, 'Unlisted product cannot be selected');
    $product->set_status('draft'); $product->save();
    check(count(VTO\Catalog::all()) === 0, 'Draft WooCommerce products stay private');
    $product->set_status('publish'); $product->save();
    $private = VTO\Storage::directory();
    check(!is_wp_error($private) && !str_starts_with(realpath($private), realpath(ABSPATH).DIRECTORY_SEPARATOR), 'Personal image storage is outside WordPress web root');
    $input = VTO\Storage::save($png); $temporary_paths[] = $input;
    check(!is_wp_error($input) && VTO\Storage::owns($input), 'Valid image saved privately');
    check(is_wp_error(VTO\Storage::save('<?php echo 1; ?>')), 'Executable/non-image bytes rejected');
    $created = VTO\Jobs::create(VTO\Catalog::find('wc:'.$product_id), $input);
    check(!is_wp_error($created) && $created['status'] === 'queued', 'Request durably queued');
    $id = $created['id']; $created_jobs[] = $id;
    $token = $created['token'];
    $row = VTO\Jobs::by_id($id);
    check($row['token_hash'] === hash('sha256', $token) && !in_array($token, $row, true), 'Only tracking token hash is stored');
    check(VTO\Jobs::by_token(str_repeat('0',64)) === null, 'Unknown tracking token returns no request');
    $settings['mode'] = 'avalai';
    $settings['api_key'] = 'integration-test-not-a-real-key';
    update_option('vto_settings', $settings, false);
    $calls = 0;
    $http_filter = function($pre, $args, $url) use (&$calls, $png) {
        if ($url !== VTO\Provider::ENDPOINT) { return $pre; }
        $calls++;
        check(substr_count($args['body'], 'name="image[]"') === 2, 'AI edit request contains person and product images');
        check(str_contains($args['body'], 'filename="person.png"') || str_contains($args['body'], 'filename="person.jpg"'), 'Customer image role is explicit');
        check($args['sslverify'] === true && $args['redirection'] === 0, 'Provider preserves TLS and does not forward credentials through redirects');
        return ['headers'=>[], 'response'=>['code'=>200,'message'=>'OK'], 'body'=>wp_json_encode(['data'=>[['b64_json'=>base64_encode($png)]]]), 'cookies'=>[]];
    };
    add_filter('pre_http_request', $http_filter, 10, 3);
    VTO\Jobs::process($id);
    $row = VTO\Jobs::by_id($id);
    check($row['status'] === 'completed' && $calls === 0, 'Queued demo snapshot never calls live paid API after settings change');
    check(is_file($row['output_path']), 'Completed demo request has a private image result');
    VTO\Jobs::process($id);
    check((int)VTO\Jobs::by_id($id)['attempts'] === 1, 'Repeated worker execution does not duplicate a completed job');
    $live_input = VTO\Storage::save($png); $temporary_paths[] = $live_input;
    $live = VTO\Jobs::create(VTO\Catalog::find('wc:'.$product_id), $live_input);
    $created_jobs[] = $live['id'];
    VTO\Jobs::process($live['id']);
    check(VTO\Jobs::by_id($live['id'])['status'] === 'completed' && $calls === 1, 'Live adapter accepts mocked valid multi-image API response');
    remove_filter('pre_http_request', $http_filter, 10);
    $live_row = VTO\Jobs::by_id($live['id']);
    $response_body = ['data'=>[['b64_json'=>'not-valid-base64!!!']]];
    $response_code = 200;
    $http_filter = function($pre, $args, $url) use (&$response_body, &$response_code) {
        if ($url !== VTO\Provider::ENDPOINT) { return $pre; }
        return ['headers'=>[], 'response'=>['code'=>$response_code,'message'=>'Mock'], 'body'=>wp_json_encode($response_body), 'cookies'=>[]];
    };
    add_filter('pre_http_request', $http_filter, 10, 3);
    $bad_image = VTO\Provider::generate($live_row);
    check(is_wp_error($bad_image) && $bad_image->get_error_code() === 'vto_provider_image', 'Malformed base64 provider output is rejected');
    $response_body = ['data'=>[['url'=>'https://unsafe.example/customer-photo']]];
    $url_only = VTO\Provider::generate($live_row);
    check(is_wp_error($url_only) && $url_only->get_error_code() === 'vto_provider_format', 'Provider output URLs are not fetched or exposed to customers');
    $response_code = 429;
    $response_body = ['error'=>['code'=>'rate_limit_exceeded']];
    $busy = VTO\Provider::generate($live_row);
    check(is_wp_error($busy) && $busy->get_error_code() === 'vto_provider_busy', 'Upstream rate limit is reported without automatic billing retry');
    $unsafe_product = $live_row; $unsafe_product['product_image_id'] = 0; $unsafe_product['product_image'] = 'http://127.0.0.1/admin';
    $unsafe = VTO\Provider::generate($unsafe_product);
    check(is_wp_error($unsafe) && $unsafe->get_error_code() === 'vto_product_image', 'Private-network product URL cannot be used for server-side requests');
    $bad_model = $live_row; $bad_model['model'] = 'invalid model?';
    check(is_wp_error(VTO\Provider::generate($bad_model)), 'Malformed model identifier is rejected before sending photos');
    remove_filter('pre_http_request', $http_filter, 10);
    $http_filter = function($pre, $args, $url) {
        if ($url !== VTO\Provider::ENDPOINT) { return $pre; }
        return ['headers'=>[], 'response'=>['code'=>401,'message'=>'Unauthorized'], 'body'=>'{"error":{"code":"invalid_api_key","message":"do not persist arbitrary upstream messages"}}', 'cookies'=>[]];
    };
    add_filter('pre_http_request', $http_filter, 10, 3);
    $bad_input = VTO\Storage::save($png); $temporary_paths[] = $bad_input;
    $bad = VTO\Jobs::create(VTO\Catalog::find('wc:'.$product_id), $bad_input);
    $created_jobs[] = $bad['id'];
    VTO\Jobs::process($bad['id']);
    $failed = VTO\Jobs::by_id($bad['id']);
    check($failed['status'] === 'failed' && $failed['error_code'] === 'vto_provider_auth', 'Provider authorization failure is diagnosed safely');
    check(!str_contains(wp_json_encode($failed), 'do not persist') && !str_contains(wp_json_encode($failed), 'integration-test-not-a-real-key'), 'Upstream raw error content and credentials do not reach job logs');
    check(VTO\Jobs::retry($bad['id']) === true, 'Admin can explicitly retry a retained failed request');
    VTO\Jobs::process($bad['id']);
    VTO\Jobs::retry($bad['id']); VTO\Jobs::process($bad['id']);
    check(is_wp_error(VTO\Jobs::retry($bad['id'])), 'Three-attempt retry cap is enforced');
    $expiry = gmdate('Y-m-d H:i:s', time()-60);
    $GLOBALS['wpdb']->update(VTO\Jobs::table(), ['expires_at'=>$expiry], ['id'=>$id]);
    check(VTO\Jobs::by_token($token) === null, 'Expired tracking link cannot retrieve images');
    $old_output = $row['output_path'];
    $orphan = VTO\Storage::save($png); $temporary_paths[] = $orphan;
    $recent = VTO\Storage::save($png); $temporary_paths[] = $recent;
    touch($orphan, time()-2*HOUR_IN_SECONDS);
    touch($live_input, time()-2*HOUR_IN_SECONDS);
    VTO\Jobs::maintenance();
    check(!file_exists($orphan) && file_exists($recent) && file_exists($live_input), 'Orphan cleanup removes only old unreferenced images and retains active/recent files');
    check(VTO\Jobs::by_id($id) === null && !file_exists($input) && !file_exists($old_output), 'Retention cleanup removes expired database row and both personal images');
    $identity = 'test-'.bin2hex(random_bytes(8));
    $rate_filter = function() use ($identity) { return $identity; };
    add_filter('vto_rate_identity', $rate_filter);
    check(VTO\Jobs::budget() === true && VTO\Jobs::budget() === true && is_wp_error(VTO\Jobs::budget()), 'Guest hourly request limit is enforced by atomic database budget');
    remove_filter('vto_rate_identity', $rate_filter);
    $settings['api_key'] = 'integration-test-not-a-real-key';
    update_option('vto_settings', $settings, false);
    $sanitized = VTO\Settings::sanitize(['api_key'=>'', 'accent_color'=>'javascript:alert(1)', 'product_ids'=>[$product_id, $product_id]]);
    check($sanitized['api_key'] === $settings['api_key'], 'Blank API key field preserves credential');
    check($sanitized['accent_color'] === $settings['accent_color'], 'Invalid CSS color cannot be persisted');
    $sanitized = VTO\Settings::sanitize(['clear_api_key'=>1]);
    check($sanitized['api_key'] === '', 'API credential deletion requires explicit clear');
    echo "\n$passed assertions passed. Live provider calls were mocked; no paid API request was made.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL '.$error->getMessage()."\n");
    $exit_code = 1;
} finally {
    if ($http_filter) { remove_filter('pre_http_request', $http_filter, 10); }
    foreach ($created_jobs as $id) { VTO\Jobs::delete((int)$id); }
    foreach ($temporary_paths as $path) { if (is_string($path)) { VTO\Storage::delete($path); } }
    if ($product_id) { wp_delete_post($product_id, true); }
    if ($attachment_id) { wp_delete_attachment($attachment_id, true); }
    if ($old_settings === null) { delete_option('vto_settings'); } else { update_option('vto_settings', $old_settings, false); }
    wp_set_current_user($old_user);
}

exit($exit_code);
