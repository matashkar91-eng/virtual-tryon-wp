<?php
/**
 * Scoped AvalAI timeout guard checks against disposable WordPress.
 * VTO_WP_ROOT=/absolute/path/to/wordpress php tests/http-timeouts.php
 * Every HTTP request is intercepted. Native cURL setters run without curl_exec.
 */
namespace VTO {
    // Isolated-process observers: record option writes and delegate to native
    // cURL. They never execute a request or inspect credentials/body content.
    function curl_setopt($handle, $option, $value) {
        $GLOBALS['vto_timeout_native_writes'][] = [$handle, $option, $value];
        return \curl_setopt($handle, $option, $value);
    }
    function curl_setopt_array($handle, array $options) {
        foreach ($options as $option => $value) {
            $GLOBALS['vto_timeout_native_writes'][] = [$handle, $option, $value];
        }
        return \curl_setopt_array($handle, $options);
    }
    function curl_errno($handle) {
        foreach ($GLOBALS['vto_timeout_errno_overrides'] ?? [] as $override) {
            if ($override[0] === $handle) { return $override[1]; }
        }
        return \curl_errno($handle);
    }
}

namespace {
    $bootstrap = getenv('VTO_WP_LOAD') ?: (getenv('VTO_WP_ROOT') ? rtrim(getenv('VTO_WP_ROOT'), '/').'/wp-load.php' : '');
    if (!$bootstrap || !is_file($bootstrap)) { fwrite(STDERR, "Set VTO_WP_ROOT to disposable WordPress.\n"); exit(2); }
    require $bootstrap;
    if (!in_array(wp_get_environment_type(), ['local', 'development'], true)) { fwrite(STDERR, "Refusing non-development database.\n"); exit(2); }
    if (!class_exists('VTO\\Provider')) { fwrite(STDERR, "Activate Virtual Try-On Studio first.\n"); exit(2); }

    $passed = 0;
    function timeout_check($condition, $message) {
        global $passed;
        if (!$condition) { throw new \RuntimeException($message); }
        $passed++;
        echo "PASS $message\n";
    }
    function timeout_hook_ids() {
        $hooks = [];
        foreach (['http_request_args', 'http_api_curl', 'requests-requests.before_request'] as $tag) {
            $hooks[$tag] = [];
            if (isset($GLOBALS['wp_filter'][$tag])) {
                foreach ($GLOBALS['wp_filter'][$tag]->callbacks as $priority => $callbacks) {
                    $hooks[$tag][$priority] = array_keys($callbacks);
                }
            }
        }
        return $hooks;
    }
    function timeout_native_options($handle) {
        $options = [];
        foreach ($GLOBALS['vto_timeout_native_writes'] ?? [] as $write) {
            if ($write[0] === $handle) { $options[$write[1]] = $write[2]; }
        }
        return $options;
    }
    function timeout_native_value(array $options, $name) {
        return defined($name) ? ($options[constant($name)] ?? null) : null;
    }
    function timeout_requests_dispatch($bridge, &$url, &$headers, &$data, &$method, &$options) {
        $bridge->dispatch('requests.before_request', [&$url, &$headers, &$data, &$method, &$options]);
    }

    $old_settings = get_option('vto_settings', null);
    $input = '';
    $attachment_id = 0;
    $poison = null;
    $intercept = null;
    $handles = [];
    $exit_code = 0;
    try {
        $settings = VTO\Settings::defaults();
        $settings['mode'] = 'avalai';
        $settings['api_key'] = 'timeout-test-not-a-real-key';
        update_option('vto_settings', $settings, false);
        $image = imagecreatetruecolor(128, 128);
        imagefill($image, 0, 0, imagecolorallocate($image, 100, 90, 160));
        ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
        $input = VTO\Storage::save($png);
        timeout_check(!is_wp_error($input), 'Private input fixture prepared');
        $uploaded = wp_upload_bits('vto-timeout-product.png', null, $png);
        timeout_check(empty($uploaded['error']), 'Local product image fixture prepared');
        $attachment_id = wp_insert_attachment(['post_title'=>'VTO timeout fixture','post_mime_type'=>'image/png','post_status'=>'inherit'], $uploaded['file']);
        update_attached_file($attachment_id, $uploaded['file']);
        $job = ['input_path'=>$input, 'mode'=>'avalai', 'model'=>$settings['model'], 'product_image_id'=>$attachment_id, 'product_name'=>'روسری آزمایشی', 'category'=>'scarf', 'prompt'=>'Preserve the consenting person.'];

        $poison = static function ($args, $url) {
            if ($url === VTO\Provider::ENDPOINT && strtoupper($args['method'] ?? '') === 'POST') {
                $args['timeout'] = 30;
                $args['sslverify'] = false;
                $args['redirection'] = 5;
                $args['reject_unsafe_urls'] = false;
                $args['headers']['Content-Type'] = 'text/plain; third-party-rewrite=1';
            }
            return $args;
        };
        add_filter('http_request_args', $poison, 100, 2);
        $behavior = 'timeout';
        $http_calls = 0;
        $observed_args = [];
        $active_hooks = [];
        $native_options = [];
        $scope_results = [];
        $marker_seen_in_body = false;
        $observed_probe_args = [];
        $probe_hook_state = [];
        $requests_state = [];
        $requests_scope_results = [];
        $replay_state = [];
        $intercept = static function ($pre, $args, $url) use (&$behavior, &$http_calls, &$observed_args, &$active_hooks, &$native_options, &$scope_results, &$marker_seen_in_body, &$observed_probe_args, &$probe_hook_state, &$requests_state, &$requests_scope_results, &$replay_state, &$handles, $poison, $png) {
            $http_calls++;
            if ($url !== VTO\Provider::ENDPOINT) {
                $observed_probe_args = $args;
                $probe_hook_state = timeout_hook_ids();
                return new WP_Error('http_request_failed', 'cURL error 28: READONLY-RAW-SENTINEL');
            }
            $observed_args = $args;
            $active_hooks = timeout_hook_ids();
            $marker = $args['_vto_edit_guard'] ?? '';
            $marker_seen_in_body = $marker && (str_contains($args['body'] ?? '', $marker) || str_contains(wp_json_encode($args['headers']), $marker));
            $cases = [
                'unrelated host' => ['https://example.invalid/v1/images/edits', ['timeout'=>7, 'method'=>'POST', '_vto_edit_guard'=>$marker], 7],
                'lookalike host' => ['https://api.avalai.ir.example.invalid/v1/images/edits', ['timeout'=>7, 'method'=>'POST', '_vto_edit_guard'=>$marker], 7],
                'different route' => [VTO\Provider::MODELS_ENDPOINT, ['timeout'=>7, 'method'=>'GET', '_vto_edit_guard'=>$marker], 7],
                'query variant' => [VTO\Provider::ENDPOINT.'?different=1', ['timeout'=>7, 'method'=>'POST', '_vto_edit_guard'=>$marker], 7],
                'GET instead of POST' => [VTO\Provider::ENDPOINT, ['timeout'=>7, 'method'=>'GET', '_vto_edit_guard'=>$marker], 7],
                'missing request marker' => [VTO\Provider::ENDPOINT, ['timeout'=>7, 'method'=>'POST'], 30],
                'foreign request marker' => [VTO\Provider::ENDPOINT, ['timeout'=>7, 'method'=>'POST', '_vto_edit_guard'=>'foreign-request-marker'], 30],
            ];
            $scope_results = [];
            foreach ($cases as $name => [$case_url, $case_args, $expected]) {
                $filtered = apply_filters('http_request_args', $case_args, $case_url);
                $scope_results[$name] = $filtered === $poison($case_args, $case_url) && $filtered['timeout'] === $expected;
            }
            $bridge = null;
            $requests_state = [];
            $requests_scope_results = [];
            $replay_state = [];
            if ($behavior !== 'arguments_only') {
                $bridge = new WP_HTTP_Requests_Hooks($url, $args);
                $requests_url = $url;
                $requests_headers = new \WpOrg\Requests\Utility\CaseInsensitiveDictionary($args['headers']);
                $requests_data = 'non-secret request fixture';
                $requests_method = 'POST';
                $requests_options = ['timeout'=>30, 'connect_timeout'=>1, 'verify'=>false, 'verifyname'=>false, 'follow_redirects'=>true, 'redirects'=>4, 'max_bytes'=>17, 'filename'=>'unwanted-stream-path'];
                if ($behavior !== 'requests_no_hooks') { $requests_options['hooks'] = $bridge; }
                $before_refs = [$requests_url, $requests_headers, $requests_data, $requests_method];
                timeout_requests_dispatch($bridge, $requests_url, $requests_headers, $requests_data, $requests_method, $requests_options);
                $requests_state = [
                    'options'=>$requests_options,
                    'other_references_unchanged'=>[$requests_url,$requests_headers,$requests_data,$requests_method] === $before_refs,
                    'headers_iterable'=>$requests_headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary,
                ];
                $requests_cases = [
                    'other host'=>['https://example.invalid/v1/images/edits', ['Content-Type'=>$args['headers']['Content-Type']], 'POST'],
                    'other route'=>[VTO\Provider::MODELS_ENDPOINT, ['Content-Type'=>$args['headers']['Content-Type']], 'POST'],
                    'query variant'=>[VTO\Provider::ENDPOINT.'?other=1', ['Content-Type'=>$args['headers']['Content-Type']], 'POST'],
                    'GET method'=>[$url, ['Content-Type'=>$args['headers']['Content-Type']], 'GET'],
                    'missing boundary'=>[$url, [], 'POST'],
                    'foreign boundary'=>[$url, ['Content-Type'=>'multipart/form-data; boundary=foreign-request'], 'POST'],
                ];
                foreach ($requests_cases as $name=>[$case_url,$case_headers,$case_method]) {
                    $case_data = 'unrelated fixture';
                    $case_options = ['timeout'=>7, 'verify'=>false, 'verifyname'=>false, 'follow_redirects'=>true];
                    $before = [$case_url,$case_headers,$case_data,$case_method,$case_options];
                    timeout_requests_dispatch($bridge, $case_url, $case_headers, $case_data, $case_method, $case_options);
                    $requests_scope_results[$name] = [$case_url,$case_headers,$case_data,$case_method,$case_options] === $before;
                }
            }
            if (function_exists('curl_init') && !in_array($behavior, ['arguments_only','requests_only'], true)) {
                $GLOBALS['vto_timeout_native_writes'] = [];
                $handle = \curl_init();
                $handles[] = $handle;
                \curl_setopt_array($handle, [CURLOPT_TIMEOUT=>30, CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_LOW_SPEED_LIMIT=>1, CURLOPT_LOW_SPEED_TIME=>1, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0, CURLOPT_FOLLOWLOCATION=>true]);
                // This installed bridge performs WordPress's real three-argument
                // http_api_curl mapping from Requests' curl.before_send hook.
                $bridge->dispatch('curl.before_send', [&$handle]);
                $native_options = timeout_native_options($handle);
                $other_handle = \curl_init();
                $handles[] = $other_handle;
                foreach ($cases as $name => [$case_url, $case_args, $expected]) {
                    do_action('http_api_curl', $other_handle, $case_args, $case_url);
                }
                $scope_results['cURL callbacks for other requests'] = timeout_native_options($other_handle) === [];
                if (in_array($behavior, ['replay23','replay61'], true)) {
                    $errno = $behavior === 'replay23' ? 23 : 61;
                    $GLOBALS['vto_timeout_errno_overrides'] = [[$handle, $errno]];
                    $foreign_bridge = new WP_HTTP_Requests_Hooks('https://example.invalid/unrelated', []);
                    $foreign_threw = false;
                    try { $foreign_bridge->dispatch('curl.after_send', []); } catch (\Throwable $exception) { $foreign_threw = true; }
                    $replay_state = ['errno'=>$errno, 'foreign_instance_untouched'=>!$foreign_threw, 'core_replay_reached'=>false, 'exception_type'=>null, 'exception_message'=>''];
                    try {
                        $bridge->dispatch('curl.after_send', []);
                        // Represents reaching the library branch immediately
                        // after after_send, where Requests otherwise replays POST.
                        $replay_state['core_replay_reached'] = true;
                    } catch (\WpOrg\Requests\Exception $exception) {
                        $replay_state['exception_type'] = $exception->getType();
                        $replay_state['exception_message'] = $exception->getMessage();
                    } finally {
                        $GLOBALS['vto_timeout_errno_overrides'] = [];
                    }
                    return new WP_Error('http_request_failed', $replay_state['exception_message'] ?: 'Replay guard did not throw');
                }
            }
            if ($behavior === 'throw') { throw new RuntimeException('controlled HTTP exception'); }
            if ($behavior === 'success') {
                return ['headers'=>[], 'response'=>['code'=>200, 'message'=>'OK'], 'body'=>wp_json_encode(['data'=>[['b64_json'=>base64_encode($png)]]]), 'cookies'=>[]];
            }
            return new WP_Error('http_request_failed', 'cURL error 28: Authorization: Bearer RAW-KEY-SENTINEL https://example.invalid/?photo=RAW-PHOTO-SENTINEL');
        };
        add_filter('pre_http_request', $intercept, PHP_INT_MAX, 3);
        $baseline_hooks = timeout_hook_ids();

        $error = VTO\Provider::generate($job);
        timeout_check(is_wp_error($error) && $error->get_error_code() === 'vto_provider_timeout', 'Guarded timeout remains a diagnosed uncertain generation failure');
        timeout_check($http_calls === 1, 'Timeout triggers one intercepted generation POST and no automatic retry');
        timeout_check($observed_args['timeout'] === VTO\Provider::EDIT_TIMEOUT, 'Late guard restores generation timeout after a third-party 30-second override');
        timeout_check($observed_args['sslverify'] === true && $observed_args['reject_unsafe_urls'] === true && $observed_args['redirection'] === 0, 'Scoped guard restores TLS verification, safe URLs and no credential redirects');
        timeout_check(str_starts_with($observed_args['headers']['Content-Type'] ?? '', 'multipart/form-data; boundary=vto-') && ($observed_args['headers']['Accept-Encoding'] ?? '') === 'identity', 'Scoped guard restores the owned multipart type and requests an identity response');
        timeout_check(($observed_args['headers']['Accept-Encoding'] ?? null) === 'identity' && $observed_args['stream'] === false && $observed_args['filename'] === null, 'Owned generation requests identity encoding and prevents response streaming');
        timeout_check(!empty($observed_args['_vto_edit_guard']) && !$marker_seen_in_body, 'Request identity marker stays in internal arguments and never enters payload or headers');
        foreach ($scope_results as $name => $safe) { timeout_check($safe, 'Guard leaves '.$name.' unchanged'); }
        timeout_check($active_hooks !== $baseline_hooks && timeout_hook_ids() === $baseline_hooks, 'Temporary argument, cURL and Requests hooks exist only during the generation request');
        $data = $error->get_error_data();
        $transport = $data['transport'] ?? [];
        timeout_check(($transport['args_guard_applied'] ?? null) === true && ($transport['timeout_before_guard_seconds'] ?? null) == 30 && ($transport['effective_timeout_seconds'] ?? null) == VTO\Provider::EDIT_TIMEOUT, 'Safe diagnostics distinguish prior timeout from effective guarded timeout');
        timeout_check(($transport['requests_guard_applied'] ?? null) === true && ($transport['replay_guard_applied'] ?? null) === true && ($transport['replay_blocked'] ?? null) === false, 'Requests bridge reports late option enforcement and per-instance replay protection truthfully');
        $requests_options = $requests_state['options'];
        timeout_check($requests_state['headers_iterable'] && $requests_state['other_references_unchanged'] && $requests_options['timeout'] === VTO\Provider::EDIT_TIMEOUT && $requests_options['connect_timeout'] === 20, 'Installed Requests five-reference bridge mutates deadlines with iterable headers and preserves other references');
        timeout_check($requests_options['verify'] === $observed_args['sslcertificates'] && $requests_options['verifyname'] === true && is_file($requests_options['verify']), 'Late Requests options restore trusted CA and host verification after native cURL hooks');
        timeout_check($requests_options['follow_redirects'] === false && $requests_options['redirects'] === 0 && $requests_options['filename'] === false && $requests_options['max_bytes'] === VTO\Provider::MAX_RESPONSE_BYTES, 'Requests layer bounds redirect, streaming and response-size behavior');
        foreach ($requests_scope_results as $name=>$safe) { timeout_check($safe, 'Requests boundary guard ignores '.$name); }
        if (function_exists('curl_init')) {
            timeout_check(($transport['curl_guard_applied'] ?? null) === true && ($transport['curl_guard_ok'] ?? null) === true && ($transport['connect_timeout_seconds'] ?? null) == 20 && ($transport['low_speed_guard_applied'] ?? null) === true, 'Diagnostics confirm native cURL option application without executing a request');
            timeout_check(timeout_native_value($native_options, 'CURLOPT_TIMEOUT_MS') === VTO\Provider::EDIT_TIMEOUT * 1000 || timeout_native_value($native_options, 'CURLOPT_TIMEOUT') === VTO\Provider::EDIT_TIMEOUT, 'Actual native cURL setter receives the configured overall deadline');
            timeout_check(timeout_native_value($native_options, 'CURLOPT_CONNECTTIMEOUT_MS') === 20000 || timeout_native_value($native_options, 'CURLOPT_CONNECTTIMEOUT') === 20, 'Native cURL connect deadline stays separate from generation deadline');
            timeout_check(timeout_native_value($native_options, 'CURLOPT_LOW_SPEED_LIMIT') === 0 && timeout_native_value($native_options, 'CURLOPT_LOW_SPEED_TIME') === 0, 'Native cURL low-speed timeout cannot silently impose a shorter deadline');
            timeout_check(timeout_native_value($native_options, 'CURLOPT_SSL_VERIFYPEER') === true && timeout_native_value($native_options, 'CURLOPT_SSL_VERIFYHOST') === 2 && timeout_native_value($native_options, 'CURLOPT_FOLLOWLOCATION') === false, 'Native cURL keeps peer/host verification and refuses redirects');
            timeout_check(timeout_native_value($native_options, 'CURLOPT_CAINFO') === $observed_args['sslcertificates'] && is_file(timeout_native_value($native_options, 'CURLOPT_CAINFO')), 'Native cURL preserves the WordPress trusted CA bundle');
            timeout_check(timeout_native_value($native_options, 'CURLOPT_ENCODING') === 'identity', 'Native cURL requests identity content encoding');
            if (defined('CURLOPT_HTTP_CONTENT_DECODING')) {
                timeout_check(timeout_native_value($native_options, 'CURLOPT_HTTP_CONTENT_DECODING') === false, 'Native cURL disables implicit response decoding');
            } else { echo "SKIP HTTP content-decoding option: constant unavailable.\n"; }
        } else {
            echo "SKIP Native cURL checks: extension unavailable.\n";
        }
        $safe_diagnostic = wp_json_encode([$error->get_error_message(), $data]);
        timeout_check(!str_contains($safe_diagnostic, 'RAW-KEY') && !str_contains($safe_diagnostic, 'RAW-PHOTO') && !str_contains($safe_diagnostic, $observed_args['_vto_edit_guard']), 'Timeout diagnostics never reveal raw keys, photo URLs or request identity markers');

        $behavior = 'arguments_only';
        $without_curl = VTO\Provider::generate($job);
        $without_curl_data = $without_curl->get_error_data();
        $without_curl_transport = $without_curl_data['transport'] ?? [];
        timeout_check(($without_curl_transport['args_guard_applied'] ?? null) === true && ($without_curl_transport['curl_guard_applied'] ?? null) === false && ($without_curl_transport['curl_guard_ok'] ?? null) === false && ($without_curl_transport['low_speed_guard_applied'] ?? null) === false, 'Diagnostics never claim native cURL enforcement when its transport hook did not run');
        timeout_check($without_curl_transport['requests_guard_applied'] === false && $without_curl_transport['replay_guard_applied'] === false && $without_curl_transport['connect_timeout_seconds'] === null, 'Argument-only failure does not invent Requests, replay or connection enforcement');
        timeout_check(timeout_hook_ids() === $baseline_hooks, 'Non-cURL or intercepted failure still removes guard hooks');

        $behavior = 'requests_only';
        $requests_only = VTO\Provider::generate($job);
        $requests_only_transport = $requests_only->get_error_data()['transport'];
        timeout_check($requests_only_transport['requests_guard_applied'] === true && $requests_only_transport['curl_guard_applied'] === false && $requests_only_transport['connect_timeout_seconds'] === 20 && timeout_hook_ids() === $baseline_hooks, 'Requests-only transport enforces its connection limit without claiming native cURL application');

        $behavior = 'requests_no_hooks';
        $without_replay_hooks = VTO\Provider::generate($job);
        $without_replay_transport = $without_replay_hooks->get_error_data()['transport'];
        timeout_check($without_replay_transport['requests_guard_applied'] === true && $without_replay_transport['replay_guard_applied'] === false && $requests_state['options']['timeout'] === VTO\Provider::EDIT_TIMEOUT && timeout_hook_ids() === $baseline_hooks, 'Missing Requests hook-manager capability preserves guarded options without a fatal error or false replay claim');

        if (function_exists('curl_init')) {
            foreach (['replay23'=>'CURLE_WRITE_ERROR', 'replay61'=>'CURLE_BAD_CONTENT_ENCODING'] as $mode=>$constant) {
                if (!defined($constant)) { echo "SKIP Replay simulation: $constant unavailable.\n"; continue; }
                $behavior = $mode;
                $before_calls = $http_calls;
                $replay_error = VTO\Provider::generate($job);
                $replay_transport = $replay_error->get_error_data()['transport'];
                timeout_check($replay_state['foreign_instance_untouched'], 'Replay protection stays local to the owned Requests hook instance for errno '.$replay_state['errno']);
                timeout_check($replay_state['exception_type'] === 'vto_post_replay_blocked' && $replay_state['core_replay_reached'] === false, 'Fixed Requests exception aborts before the core POST replay branch for errno '.$replay_state['errno']);
                timeout_check($replay_transport['replay_guard_applied'] === true && $replay_transport['replay_blocked'] === true && $http_calls === $before_calls+1, 'Replay diagnostic records prevention with a single generation POST for errno '.$replay_state['errno']);
                timeout_check(!str_contains($replay_state['exception_message'], 'RAW-') && timeout_hook_ids() === $baseline_hooks, 'Replay failure message stays fixed and every global guard hook is removed');
            }
        }

        $behavior = 'success';
        $before_calls = $http_calls;
        $success = VTO\Provider::generate($job);
        timeout_check(!is_wp_error($success) && $success['bytes'] === $png && $http_calls === $before_calls + 1, 'Successful guarded request retains normal image-decoding behavior');
        timeout_check(timeout_hook_ids() === $baseline_hooks, 'Guard hooks are removed after successful image response');

        $behavior = 'throw';
        $thrown = false;
        try { VTO\Provider::generate($job); } catch (RuntimeException $exception) { $thrown = $exception->getMessage() === 'controlled HTTP exception'; }
        timeout_check($thrown && timeout_hook_ids() === $baseline_hooks, 'Unexpected HTTP exception still removes every temporary guard hook');

        $before_calls = $http_calls;
        $probe = VTO\Provider::check_connection();
        timeout_check(is_wp_error($probe) && $http_calls === $before_calls + 1 && timeout_hook_ids() === $baseline_hooks, 'Read-only model probe remains outside generation guard and does not leak hooks');
        timeout_check($observed_probe_args['timeout'] === VTO\Provider::PROBE_TIMEOUT && $observed_probe_args['method'] === 'GET' && empty($observed_probe_args['_vto_edit_guard']) && $probe_hook_state === $baseline_hooks, 'Read-only model probe retains its own short deadline without generation hooks');
        echo "\n$passed timeout-guard assertions passed. No curl_exec or live HTTP call occurred.\n";
    } catch (\Throwable $error) {
        fwrite(STDERR, 'FAIL '.$error->getMessage().PHP_EOL);
        $exit_code = 1;
    } finally {
        if ($poison) { remove_filter('http_request_args', $poison, 100); }
        if ($intercept) { remove_filter('pre_http_request', $intercept, PHP_INT_MAX); }
        foreach ($handles as $handle) { \curl_close($handle); }
        if (is_string($input) && $input) { VTO\Storage::delete($input); }
        if ($attachment_id) { wp_delete_attachment($attachment_id, true); }
        if ($old_settings === null) { delete_option('vto_settings'); } else { update_option('vto_settings', $old_settings, false); }
    }
    exit($exit_code);
}
