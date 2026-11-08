<?php
/**
 * Image providers. No credentials or provider response bodies are logged.
 *
 * The AvalAI adapter uses the OpenAI-compatible images/edits contract. AvalAI
 * endpoint/model compatibility and live billing must be checked against the
 * merchant's account before enabling production usage; demo is the default.
 */
namespace VTO;

defined('ABSPATH') || exit;

final class Provider {
    const ENDPOINT = 'https://api.avalai.ir/v1/images/edits';
    const MODELS_ENDPOINT = 'https://api.avalai.ir/v1/models';
    const EDIT_TIMEOUT = 110;
    const EDIT_CONNECT_TIMEOUT = 20;
    const PROBE_TIMEOUT = 15;
    const MAX_MODELS_RESPONSE_BYTES = 4194304;
    const MAX_IMAGE_BYTES = 15728640;
    const MAX_RESPONSE_BYTES = 23068672;

    /**
     * Run once per queue attempt. Automatic retries are intentionally delegated to
     * Jobs: a timeout may still be billed and must never silently resubmit.
     *
     * @return array|\WP_Error Image bytes + verified MIME type.
     */
    public static function generate(array $job) {
        $settings = Settings::get();
        $input = self::local_image(isset($job['input_path']) ? $job['input_path'] : '');
        if (is_wp_error($input)) {
            return $input;
        }
        $mode = isset($job['mode']) ? (string) $job['mode'] : (string) $settings['mode'];
        if ('demo' === $mode) {
            // Simulation is disclosed by the storefront; this is NOT a try-on.
            return $input;
        }
        if ('avalai' !== $mode) {
            return self::error('vto_provider_mode', 'سرویس پردازش هنوز تنظیم نشده است.', 'Unsupported provider mode.');
        }
        $key = defined('VTO_AVALAI_KEY') ? (string) VTO_AVALAI_KEY : (string) $settings['api_key'];
        $key = trim($key);
        if ('' === $key || preg_match('/[\r\n]/', $key)) {
            return self::error('vto_provider_key', 'سرویس پردازش فروشگاه هنوز آماده نیست. لطفاً با پشتیبانی تماس بگیرید.', 'Missing or invalid AvalAI API key.');
        }
        $model = isset($job['model']) ? trim((string) $job['model']) : (isset($settings['model']) ? trim((string) $settings['model']) : '');
        if (!Settings::valid_model($model)) {
            return self::error('vto_provider_model', 'مدل پردازش فروشگاه هنوز تنظیم نشده است.', 'An image-edit-capable AvalAI model is required.');
        }
        $has_quality_snapshot = array_key_exists('quality', $job);
        $quality = $has_quality_snapshot ? $job['quality'] : (isset($settings['quality']) ? $settings['quality'] : 'low');
        // Rows created before 0.1.6 did not record the provider-selected tier.
        // Keep that audit fact in storage, but make an explicit manual retry economical.
        if ($has_quality_snapshot && 'legacy' === $quality) {
            $quality = 'low';
        }
        if (!Settings::valid_quality($quality)) {
            return self::error('vto_provider_quality', 'کیفیت ساخت تصویر معتبر نیست؛ تنظیمات فروشگاه را بررسی کنید.', 'Image edit quality must be one of low, medium, high or auto.');
        }
        $product = self::product_image($job);
        if (is_wp_error($product)) {
            return $product;
        }
        $category = isset($job['category']) ? (string) $job['category'] : '';
        $product_detail = self::product_detail($product, $category);
        // A site product photo may contain another model. Put the automatically
        // extracted fabric/detail first so the provider sees a product design,
        // then keep the original store photo only as drape/context.
        $product_design = is_array($product_detail) ? $product_detail : $product;
        $has_product_context = !hash_equals(hash('sha256', $product_design['bytes']), hash('sha256', $product['bytes']));
        $prompt = isset($job['prompt']) ? (string) $job['prompt'] : (string) $settings['prompt'];
        $prompt = strtr($prompt, array(
            '{product}' => isset($job['product_name']) ? (string) $job['product_name'] : '',
            '{category}' => isset($job['category']) ? (string) $job['category'] : '',
        ));
        // Establish non-negotiable image roles and replacement requirements even
        // when a merchant supplies a short or ambiguous custom prompt.
        $prompt = self::replacement_instructions($category, $has_product_context) . "\n\nMerchant instructions:\n" . $prompt;
        $boundary = 'vto-' . bin2hex(random_bytes(18));
        $body = self::field($boundary, 'model', $model)
            . self::field($boundary, 'prompt', substr($prompt, 0, 12000))
            . self::field($boundary, 'quality', $quality)
            . self::field($boundary, 'n', '1')
            . self::field($boundary, 'size', '1024x1024')
            . self::image_part($boundary, 'person', $input)
            . self::image_part($boundary, 'product-design', $product_design)
            . ($has_product_context ? self::image_part($boundary, 'product-context', $product) : '')
            . '--' . $boundary . "--\r\n";

        $started = self::clock();
        $transport = array();
        $response = self::edit_request(array(
            'timeout' => self::EDIT_TIMEOUT,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                'Accept' => 'application/json',
            ),
            'body' => $body,
            'data_format' => 'body',
        ), $transport);
        $elapsed = max(0.0, self::clock() - $started);
        unset($body, $key);
        if (is_wp_error($response)) {
            return self::with_transport_diagnostics(self::transport_error($response, $elapsed, self::EDIT_TIMEOUT, true), $transport);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        if (strlen($raw) >= self::MAX_RESPONSE_BYTES) {
            return self::with_transport_diagnostics(self::error('vto_provider_response_size', 'حجم پاسخ سرویس بیش از حد مجاز بود. لطفاً با پشتیبانی تماس بگیرید.', 'AvalAI response exceeded the configured maximum.'), $transport);
        }
        if ($status < 200 || $status > 299) {
            $data = json_decode($raw, true, 64);
            return self::with_transport_diagnostics(self::http_error($status, $data), $transport);
        }
        unset($raw);
        return self::with_transport_diagnostics(Provider_Result::decode($response, $job), $transport);
    }

    private static function replacement_instructions(string $category, bool $has_context): string {
        $base = "Create ONE photorealistic virtual try-on from the supplied references. "
            . "REFERENCE ROLES — DO NOT SWAP THEM: Image 1 = CUSTOMER and the ONLY identity allowed in the result. Image 2 = exact PRODUCT DESIGN automatically derived from the selected store product photo; it is not an identity reference. ";
        if ($has_context) {
            $base .= "Image 3 = the original full STORE PRODUCT PHOTO for drape and scale only. It may contain another model: ignore that person's face, identity, body, clothing, pose and background completely. ";
        }
        $base .= "Make a clearly visible replacement in Image 1: completely remove the existing item of the same type and replace it with the selected product shown by Images 2"
            . ($has_context ? " and 3. " : ". ")
            . "Use the exact visible colors, motifs, layout, border and material of the selected product. Do not invent, simplify or redesign its print. "
            . "Do not preserve, recolor or blend the customer's original item. DO NOT return Image 1 unchanged. The result is invalid if the original item remains or the selected product's distinctive design is missing. "
            . "Preserve the customer's identity, recognizable face, skin, body proportions, pose, lighting and background. Change only the relevant garment or accessory. Do not add text, logos or a watermark. "
            . "These role and replacement requirements take priority over any conflicting merchant instruction below. ";
        $specific = array(
            'scarf' => "For a scarf or hijab, fully replace the scarf currently covering the head, neck and shoulders. Do not combine the old scarf with the new one. Reproduce the reference scarf's visible print, colors, border and fabric appearance, then drape it naturally around the same person.",
            'clothing' => "For clothing, replace only the selected garment while keeping the person's body, other clothing and accessories unchanged. Match the reference garment's cut, color, print, fabric and visible details.",
            'glasses' => "For glasses, remove any existing eyewear and place the exact reference frame naturally on the same face. Keep the eyes and facial features unchanged, and make the frame shape and color clearly recognizable.",
            'jewelry' => "For jewelry, replace the matching existing accessory and make the exact reference piece clearly visible in its natural wearing position. Preserve the face, skin and all unrelated accessories.",
        );
        return $base . (isset($specific[$category]) ? $specific[$category] : $specific['clothing']);
    }

    /**
     * Derive the design reference from the store's own product photograph. The
     * crop removes most of the other model's face while retaining the scarf on
     * the head/shoulders and its lower fabric. The original photo remains a
     * separate context reference. No merchant upload is required.
     */
    private static function product_detail(array $image, string $category) {
        if ('scarf' !== $category || !function_exists('imagecreatefromstring') || !function_exists('imagecopyresampled')) { return null; }
        $info = @getimagesizefromstring($image['bytes']);
        if (!$info || $info[0] < 96 || $info[1] < 96 || $info[0] * $info[1] > 12000000) { return null; }
        $source = null; $detail = null;
        try {
            $source = @imagecreatefromstring($image['bytes']);
            if (!$source) { return null; }
            $width = imagesx($source); $height = imagesy($source);
            // Lower-middle detail: keep the draped scarf/pattern while leaving
            // the product model's face outside the primary design reference.
            $crop_width = max(64, (int) floor($width * 0.82));
            $crop_height = max(64, (int) floor($height * 0.58));
            if ($crop_width > $width || $crop_height > $height) { return null; }
            $source_x = (int) floor(($width - $crop_width) / 2);
            $source_y = min($height - $crop_height, (int) floor($height * 0.38));
            $scale = min(1.0, 1024 / max($crop_width, $crop_height));
            $target_width = max(64, (int) round($crop_width * $scale));
            $target_height = max(64, (int) round($crop_height * $scale));
            $detail = imagecreatetruecolor($target_width, $target_height);
            if (!$detail) { return null; }
            $white = imagecolorallocate($detail, 255, 255, 255);
            imagefill($detail, 0, 0, $white);
            if (!imagecopyresampled($detail, $source, 0, 0, $source_x, $source_y, $target_width, $target_height, $crop_width, $crop_height)) { return null; }
            ob_start();
            $encoded = imagejpeg($detail, null, 92);
            $bytes = ob_get_clean();
            if (!$encoded || !is_string($bytes)) { return null; }
            $validated = self::validate_image($bytes);
            return is_wp_error($validated) ? null : $validated;
        } catch (\Throwable $ignored) {
            if (ob_get_level() > 0) { @ob_end_clean(); }
            return null;
        } finally {
            if ($source) { imagedestroy($source); }
            if ($detail) { imagedestroy($detail); }
        }
    }

    /**
     * Keep timeout overrides local to this one owned edit request. The marker is
     * an internal HTTP argument, never an outbound header or multipart field.
     * The plugin issues no alternative transport, fallback or second POST.
     */
    private static function edit_request(array $args, array &$diagnostics) {
        $marker = bin2hex(random_bytes(16));
        $args['method'] = 'POST';
        $args['_vto_edit_guard'] = $marker;
        $content_type = $args['headers']['Content-Type'];
        $owned_handle = null;
        $default_ca = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        $ca_bundle = is_file($default_ca) ? $default_ca : true;
        $diagnostics = array(
            'args_guard_applied' => false, 'curl_guard_applied' => false, 'curl_guard_ok' => false,
            'timeout_before_guard_seconds' => null, 'effective_timeout_seconds' => null,
            'connect_timeout_seconds' => null, 'low_speed_guard_applied' => false,
            'requests_guard_applied' => false, 'replay_guard_applied' => false, 'replay_blocked' => false,
        );
        $owns = static function ($request, $url) use ($marker) {
            return $url === self::ENDPOINT && is_array($request)
                && strtoupper((string) ($request['method'] ?? '')) === 'POST'
                && isset($request['_vto_edit_guard']) && is_string($request['_vto_edit_guard'])
                && hash_equals($marker, $request['_vto_edit_guard']);
        };
        $args_guard = static function ($request, $url) use ($owns, $content_type, &$diagnostics, &$ca_bundle) {
            if (!$owns($request, $url)) { return $request; }
            $observed = isset($request['timeout']) && is_numeric($request['timeout']) ? (float) $request['timeout'] : null;
            $diagnostics['timeout_before_guard_seconds'] = $observed !== null && is_finite($observed) && $observed >= 0 && $observed <= 86400 ? $observed : null;
            $request['timeout'] = self::EDIT_TIMEOUT;
            $request['redirection'] = 0;
            $request['sslverify'] = true;
            if (isset($request['sslcertificates']) && is_string($request['sslcertificates']) && is_file($request['sslcertificates'])) {
                $ca_bundle = $request['sslcertificates'];
            }
            $request['reject_unsafe_urls'] = true;
            $request['blocking'] = true;
            $request['stream'] = false;
            $request['filename'] = null;
            $request['limit_response_size'] = self::MAX_RESPONSE_BYTES;
            if (is_string($request['headers'] ?? null)) {
                $processed = \WP_Http::processHeaders($request['headers']);
                $request['headers'] = $processed['headers'];
            }
            if (is_array($request['headers'] ?? null)) {
                foreach (array_keys($request['headers']) as $name) {
                    if (is_string($name) && in_array(strtolower($name), array('accept-encoding', 'content-type'), true)) { unset($request['headers'][$name]); }
                }
                $request['headers']['Content-Type'] = $content_type;
                $request['headers']['Accept-Encoding'] = 'identity';
            }
            $diagnostics['args_guard_applied'] = true;
            $diagnostics['effective_timeout_seconds'] = self::EDIT_TIMEOUT;
            return $request;
        };
        $curl_guard = static function ($handle, $request, $url) use ($owns, &$diagnostics, &$owned_handle) {
            if (!$owns($request, $url) || !function_exists('curl_setopt')) { return; }
            $diagnostics['curl_guard_applied'] = true;
            $owned_handle = $handle;
            $options = array(
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_LOW_SPEED_LIMIT => 0,
                CURLOPT_LOW_SPEED_TIME => 0,
                CURLOPT_ENCODING => 'identity',
            );
            if (defined('CURLOPT_HTTP_CONTENT_DECODING')) { $options[CURLOPT_HTTP_CONTENT_DECODING] = false; }
            $options[defined('CURLOPT_TIMEOUT_MS') ? CURLOPT_TIMEOUT_MS : CURLOPT_TIMEOUT] = defined('CURLOPT_TIMEOUT_MS') ? self::EDIT_TIMEOUT * 1000 : self::EDIT_TIMEOUT;
            $options[defined('CURLOPT_CONNECTTIMEOUT_MS') ? CURLOPT_CONNECTTIMEOUT_MS : CURLOPT_CONNECTTIMEOUT] = defined('CURLOPT_CONNECTTIMEOUT_MS') ? self::EDIT_CONNECT_TIMEOUT * 1000 : self::EDIT_CONNECT_TIMEOUT;
            // Preserve WordPress's configured CA bundle when restoring strict
            // peer verification after another plugin's cURL hook.
            if (isset($request['sslcertificates']) && is_string($request['sslcertificates']) && is_file($request['sslcertificates'])) {
                $options[CURLOPT_CAINFO] = $request['sslcertificates'];
            }
            $ok = true;
            foreach ($options as $option => $value) {
                $set = curl_setopt($handle, $option, $value);
                $ok = $set && $ok;
            }
            $diagnostics['curl_guard_ok'] = $ok;
            $diagnostics['low_speed_guard_applied'] = $ok;
            if ($ok) {
                $diagnostics['effective_timeout_seconds'] = self::EDIT_TIMEOUT;
                $diagnostics['connect_timeout_seconds'] = self::EDIT_CONNECT_TIMEOUT;
            }
        };
        // WordPress maps Requests' documented before_request hook with these
        // five reference parameters. Its verify option is applied AFTER the
        // http_api_curl hook, so restore it here as well. The random multipart
        // boundary identifies this owned request without reading/logging photos.
        $requests_guard = static function (&$url, &$headers, &$data, &$method, &$options) use ($content_type, &$diagnostics, &$owned_handle, &$ca_bundle) {
            if ($url !== self::ENDPOINT || strtoupper((string) $method) !== 'POST' || !is_iterable($headers) || !is_array($options)) { return; }
            $matches = false;
            foreach ($headers as $name => $value) {
                if (is_string($name) && strtolower($name) === 'content-type' && $value === $content_type) { $matches = true; break; }
            }
            if (!$matches) { return; }
            $options['timeout'] = self::EDIT_TIMEOUT;
            $options['connect_timeout'] = self::EDIT_CONNECT_TIMEOUT;
            $options['verify'] = $ca_bundle;
            $options['verifyname'] = true;
            $options['follow_redirects'] = false;
            $options['redirects'] = 0;
            $options['filename'] = false;
            $options['max_bytes'] = self::MAX_RESPONSE_BYTES;
            $diagnostics['requests_guard_applied'] = true;
            $diagnostics['effective_timeout_seconds'] = self::EDIT_TIMEOUT;
            $diagnostics['connect_timeout_seconds'] = self::EDIT_CONNECT_TIMEOUT;
            if (isset($options['hooks']) && is_object($options['hooks']) && is_callable(array($options['hooks'], 'register')) && class_exists(\WpOrg\Requests\Exception::class)) {
                // Requests otherwise retries curl_exec on encoding/write errors,
                // including POST. Abort before that branch on this hook instance
                // only, with a fixed message that contains no upstream content.
                $options['hooks']->register('curl.after_send', static function () use (&$owned_handle, &$diagnostics) {
                    if ($owned_handle === null || !function_exists('curl_errno')) { return; }
                    $errno = curl_errno($owned_handle);
                    $replay_errors = array();
                    if (defined('CURLE_WRITE_ERROR')) { $replay_errors[] = CURLE_WRITE_ERROR; }
                    if (defined('CURLE_BAD_CONTENT_ENCODING')) { $replay_errors[] = CURLE_BAD_CONTENT_ENCODING; }
                    if (in_array($errno, $replay_errors, true)) {
                        $diagnostics['replay_blocked'] = true;
                        throw new \WpOrg\Requests\Exception('cURL error ' . (int) $errno . ': Response transfer failed; automatic POST replay was prevented.', 'vto_post_replay_blocked');
                    }
                });
                $diagnostics['replay_guard_applied'] = true;
            }
        };
        add_filter('http_request_args', $args_guard, PHP_INT_MAX, 2);
        add_action('http_api_curl', $curl_guard, PHP_INT_MAX, 3);
        add_action('requests-requests.before_request', $requests_guard, PHP_INT_MAX, 5);
        try {
            return wp_safe_remote_post(self::ENDPOINT, $args);
        } finally {
            remove_filter('http_request_args', $args_guard, PHP_INT_MAX);
            remove_action('http_api_curl', $curl_guard, PHP_INT_MAX);
            remove_action('requests-requests.before_request', $requests_guard, PHP_INT_MAX);
        }
    }

    /** Append only locally produced numbers/booleans, never HTTP arguments. */
    private static function with_transport_diagnostics($result, array $transport) {
        if (!is_wp_error($result)) { return $result; }
        $data = $result->get_error_data();
        $data = is_array($data) ? $data : array();
        $before = $transport['timeout_before_guard_seconds'] ?? null;
        $effective = $transport['effective_timeout_seconds'] ?? null;
        $detail = '; args_guard=' . (!empty($transport['args_guard_applied']) ? 'applied' : 'not-applied')
            . '; timeout_before_guard_seconds=' . ($before === null ? 'unknown' : number_format($before, 2, '.', ''))
            . '; effective_timeout_seconds=' . ($effective === null ? 'unknown' : (int) $effective)
            . '; curl_guard=' . (!empty($transport['curl_guard_applied']) ? 'applied' : 'not-applied')
            . '; curl_guard_ok=' . (!empty($transport['curl_guard_ok']) ? 'true' : 'false')
            . '; connect_timeout_seconds=' . (($transport['connect_timeout_seconds'] ?? null) === null ? 'unknown' : (int) $transport['connect_timeout_seconds'])
            . '; low_speed_guard=' . (!empty($transport['low_speed_guard_applied']) ? 'applied' : 'not-applied')
            . '; requests_guard=' . (!empty($transport['requests_guard_applied']) ? 'applied' : 'not-applied')
            . '; replay_guard=' . (!empty($transport['replay_guard_applied']) ? 'applied' : 'not-applied')
            . '; replay_blocked=' . (!empty($transport['replay_blocked']) ? 'true' : 'false') . '.';
        $data['detail'] = (isset($data['detail']) && is_string($data['detail']) ? $data['detail'] : 'AvalAI request failed.') . $detail;
        $data['transport'] = $transport;
        $result->add_data($data);
        return $result;
    }

    /**
     * Read-only account/model probe. This never uploads an image or generates
     * one; a listed model does not establish image-edit support or quality.
     *
     * @return array|\WP_Error
     */
    public static function check_connection() {
        $settings = Settings::get();
        $key = defined('VTO_AVALAI_KEY') ? (string) VTO_AVALAI_KEY : (string) $settings['api_key'];
        $key = trim($key);
        if ('' === $key || preg_match('/[\r\n]/', $key)) {
            return self::error('vto_provider_key', 'کلید API تنظیم نشده یا معتبر نیست.', 'Missing or invalid AvalAI API key.');
        }
        $model = trim((string) $settings['model']);
        if (!Settings::valid_model($model)) {
            return self::error('vto_provider_model', 'شناسهٔ مدل معتبر نیست؛ ابتدا تنظیمات را ذخیره کنید.', 'An image-edit-capable AvalAI model is required.');
        }
        $started = self::clock();
        $response = wp_safe_remote_get(self::MODELS_ENDPOINT, array(
            'timeout' => self::PROBE_TIMEOUT,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => self::MAX_MODELS_RESPONSE_BYTES,
            'headers' => array('Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'),
        ));
        $elapsed = max(0.0, self::clock() - $started);
        unset($key);
        if (is_wp_error($response)) {
            return self::transport_error($response, $elapsed, self::PROBE_TIMEOUT, false);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        if (strlen($raw) >= self::MAX_MODELS_RESPONSE_BYTES) {
            return self::error('vto_probe_response_size', 'حجم فهرست مدل‌ها بیش از حد مجاز بود؛ نتیجهٔ اتصال تأیید نشد.', 'AvalAI model catalog response exceeded the configured maximum.');
        }
        $data = json_decode($raw, true, 64);
        unset($raw);
        if ($status < 200 || $status > 299) {
            return self::http_error($status, $data);
        }
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data']) || !array_is_list($data['data'])) {
            return self::error('vto_probe_format', 'پاسخ فهرست مدل‌ها قابل خواندن نبود؛ نتیجهٔ اتصال تأیید نشد.', 'AvalAI did not return an OpenAI-compatible model catalog in data[].');
        }
        $found = false;
        $count = 0;
        foreach ($data['data'] as $entry) {
            if (!is_array($entry) || empty($entry['id']) || !is_string($entry['id'])) { continue; }
            $count++;
            if ($entry['id'] === $model) { $found = true; }
        }
        if ($data['data'] && !$count) {
            return self::error('vto_probe_format', 'فهرست مدل‌های سرویس ساختار معتبری ندارد؛ نتیجهٔ اتصال تأیید نشد.', 'AvalAI model catalog contained no valid model identifiers.');
        }
        return array('model' => $model, 'model_found' => $found, 'model_count' => $count, 'checked_at' => gmdate('c'));
    }

    /** Use a monotonic clock when supported; never record the raw error text. */
    private static function clock() {
        return function_exists('hrtime') ? hrtime(true) / 1000000000 : microtime(true);
    }

    private static function transport_error(\WP_Error $error, $elapsed, $timeout, $edit) {
        $errno = null;
        // The rest of this string may contain a key, URL or photo payload.
        // Extract only a bounded numeric cURL error identifier; discard all text.
        if (preg_match('/\bcURL\s+error\s+([0-9]{1,3})\b/i', $error->get_error_message(), $matches)) {
            $errno = (int) $matches[1];
        }
        $allowed_codes = array('http_request_failed', 'http_request_not_executed', 'http_request_rejected', 'http_request_not_safe');
        $wp_code = in_array($error->get_error_code(), $allowed_codes, true) ? $error->get_error_code() : 'other';
        $category = 'transport';
        $message = 'ارتباط با سرویس پردازش برقرار نشد یا پاسخ کامل دریافت نشد.';
        if (28 === $errno) {
            $category = 'timeout';
            $message = 'زمان انتظار برای پاسخ سرویس به پایان رسید.';
        } elseif (in_array($errno, array(5, 6), true)) {
            $category = 'dns';
            $message = 'سرور فروشگاه نتوانست نام سرویس یا پراکسی را پیدا کند؛ پشتیبانی هاست باید DNS را بررسی کند.';
        } elseif (7 === $errno) {
            $category = 'connect';
            $message = 'اتصال سرور فروشگاه به سرویس برقرار نشد؛ پشتیبانی هاست باید دسترسی خروجی را بررسی کند.';
        } elseif (in_array($errno, array(35, 51, 58, 60, 77), true)) {
            $category = 'tls';
            $message = 'اتصال امن سرور به سرویس تأیید نشد؛ پشتیبانی هاست باید گواهی و تنظیمات TLS را بررسی کند.';
        } elseif (in_array($wp_code, array('http_request_not_executed', 'http_request_rejected', 'http_request_not_safe'), true)) {
            $category = 'blocked';
            $message = 'درخواست خروجی سرور توسط تنظیمات وردپرس یا میزبان متوقف شد؛ پشتیبانی باید دسترسی سرویس را بررسی کند.';
        } elseif (56 === $errno) {
            // Error 56 is a receive failure, not proof of a proxy problem.
            $message = 'ارتباط با سرویس هنگام دریافت پاسخ قطع شد.';
        }
        $detail = 'AvalAI ' . ($edit ? 'image edit' : 'read-only model probe') . ' transport failure; classification=' . $category
            . '; wp_error=' . $wp_code . '; ' . ($errno === null ? 'cURL error unavailable' : 'cURL error ' . $errno)
            . '; elapsed_seconds=' . number_format(max(0.0, (float) $elapsed), 2, '.', '')
            . '; configured_timeout_seconds=' . (int) $timeout . '.';
        if ($edit) {
            $message .= ' ممکن است درخواست پردازش شده باشد؛ برای جلوگیری از هزینهٔ تکراری با پشتیبانی تماس بگیرید.';
            $detail .= ' Edit completion and billing are unknown. No automatic retry.';
        } else {
            $message .= ' این بررسی هیچ تصویری برای ساخت ارسال نکرد.';
            $detail .= ' No image was uploaded or generated; edit compatibility was not tested.';
        }
        return new \WP_Error('vto_provider_' . $category, $message, array(
            'detail' => $detail, 'classification' => $category, 'curl_errno' => $errno,
            'elapsed_seconds' => round(max(0.0, (float) $elapsed), 2), 'timeout_seconds' => (int) $timeout,
        ));
    }

    private static function product_image(array $job) {
        // Use an attachment on disk when possible. This avoids sending a request
        // to a store whose hostname resolves to a private address on the server.
        if (!empty($job['product_image_id'])) {
            $path = get_attached_file(absint($job['product_image_id']));
            $uploads = wp_upload_dir();
            $base = realpath($uploads['basedir']);
            $real = is_string($path) ? realpath($path) : false;
            if ($base && $real && 0 === strpos($real, $base . DIRECTORY_SEPARATOR)) {
                return self::local_image($real);
            }
        }
        $url = isset($job['product_image']) ? (string) $job['product_image'] : '';
        if (!wp_http_validate_url($url)) {
            return self::error('vto_product_image', 'عکس این محصول در دسترس نیست. لطفاً محصول دیگری انتخاب کنید.', 'Product image URL is missing or is not a safe public HTTP(S) URL.');
        }
        $response = wp_safe_remote_get($url, array(
            'timeout' => 25,
            'redirection' => 3,
            'sslverify' => true,
            'limit_response_size' => self::MAX_IMAGE_BYTES + 1,
            'headers' => array('Accept' => 'image/png,image/jpeg,image/webp'),
        ));
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return self::error('vto_product_image', 'عکس این محصول در دسترس نیست. لطفاً محصول دیگری انتخاب کنید.', 'Could not safely fetch the public product image.');
        }
        $image = self::validate_image(wp_remote_retrieve_body($response));
        if (is_wp_error($image)) {
            return self::error('vto_product_image', 'عکس محصول باید JPEG، PNG یا WebP معتبر با حجم حداکثر ۱۵ مگابایت باشد.', 'Product image failed MIME, dimensions or size validation.');
        }
        return $image;
    }

    private static function local_image($path) {
        if (!is_string($path) || !is_file($path) || !is_readable($path)) {
            return self::error('vto_input_image', 'عکس ورودی دیگر در دسترس نیست. لطفاً درخواست تازه‌ای ثبت کنید.', 'Input image does not exist or is not readable.');
        }
        $size = filesize($path);
        if (false === $size || $size > self::MAX_IMAGE_BYTES) {
            return self::error('vto_input_image', 'حجم عکس بیش از حد مجاز است.', 'Input image exceeds provider byte limit.');
        }
        $bytes = file_get_contents($path);
        return self::validate_image($bytes);
    }

    private static function validate_image($bytes) {
        if (!is_string($bytes) || '' === $bytes || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return self::error('vto_invalid_image', 'تصویر معتبر نیست یا حجم آن بیش از حد مجاز است.', 'Image is empty or exceeds the byte limit.');
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || empty($info['mime']) || !in_array($info['mime'], array('image/jpeg', 'image/png', 'image/webp'), true)
            || $info[0] < 32 || $info[1] < 32 || $info[0] > 10000 || $info[1] > 10000 || $info[0] * $info[1] > 40000000) {
            return self::error('vto_invalid_image', 'فقط عکس JPEG، PNG یا WebP با ابعاد معتبر پذیرفته می‌شود.', 'Unsupported or invalid image encoding/dimensions.');
        }
        return array('bytes' => $bytes, 'mime' => $info['mime']);
    }

    private static function field($boundary, $name, $value) {
        return '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n" . $value . "\r\n";
    }

    private static function image_part($boundary, $filename, array $image) {
        $extensions = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
        return '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"image[]\"; filename=\"" . $filename . '.' . $extensions[$image['mime']]
            . "\"\r\nContent-Type: " . $image['mime'] . "\r\n\r\n" . $image['bytes'] . "\r\n";
    }

    private static function http_error($status, $data) {
        $provider_code = '';
        $allowed_codes = array(
            'invalid_api_key', 'authentication_error', 'insufficient_quota', 'rate_limit_exceeded',
            'model_not_found', 'invalid_request_error', 'content_policy_violation', 'billing_hard_limit_reached',
            'unsupported_model', 'invalid_value', 'image_parse_error', 'permission_denied',
        );
        if (is_array($data) && isset($data['error']['code']) && in_array($data['error']['code'], $allowed_codes, true)) {
            // Even a syntactically valid upstream code could contain a key.
            // Retain only known, fixed diagnostic identifiers.
            $provider_code = $data['error']['code'];
        }
        $detail = 'AvalAI HTTP ' . $status . ($provider_code ? '; provider code: ' . $provider_code : '') . '.';
        if (401 === $status || 403 === $status) {
            return self::error('vto_provider_auth', 'سرویس فروشگاه موقتاً در دسترس نیست. لطفاً با پشتیبانی تماس بگیرید.', $detail . ' Check API key, model access and account permissions.');
        }
        if (402 === $status) {
            return self::error('vto_provider_credit', 'سرویس فروشگاه موقتاً در دسترس نیست. لطفاً با پشتیبانی تماس بگیرید.', $detail . ' Check the AvalAI account credit.');
        }
        if (429 === $status) {
            return self::error('vto_provider_busy', 'سرویس در حال حاضر شلوغ است. لطفاً کمی بعد دوباره تلاش کنید.', $detail . ' Rate or quota limit; no automatic resubmission.');
        }
        if (400 === $status || 422 === $status) {
            return self::error('vto_provider_rejected', 'سرویس این درخواست را نپذیرفت. عکس واضح و محصول مناسبی انتخاب کنید؛ اگر تکرار شد با پشتیبانی تماس بگیرید.', $detail . ' Check image suitability, model and multi-image edit support.');
        }
        if (404 === $status || 405 === $status) {
            return self::error('vto_provider_endpoint', 'تنظیمات سرویس نیاز به بررسی پشتیبانی دارد.', $detail . ' Confirm AvalAI supports /v1/images/edits for the selected model.');
        }
        return self::error('vto_provider_unavailable', 'سرویس پردازش موقتاً پاسخ نداد. لطفاً کمی بعد وضعیت درخواست را بررسی کنید.', $detail . ' Provider unavailable; request completion may be unknown.');
    }

    private static function error($code, $message, $detail) {
        return new \WP_Error($code, $message, array('detail' => $detail));
    }
}
