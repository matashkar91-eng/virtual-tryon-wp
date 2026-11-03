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
        $product = self::product_image($job);
        if (is_wp_error($product)) {
            return $product;
        }
        $prompt = isset($job['prompt']) ? (string) $job['prompt'] : (string) $settings['prompt'];
        $prompt = strtr($prompt, array(
            '{product}' => isset($job['product_name']) ? (string) $job['product_name'] : '',
            '{category}' => isset($job['category']) ? (string) $job['category'] : '',
        ));
        // Establish image roles even when the merchant replaces the prompt.
        $prompt = "Image 1 is the consenting customer's photograph. Image 2 is the product reference. Edit image 1 to show that exact product worn naturally. Preserve the person's identity, face, pose and background. Only change the relevant garment or accessory. Do not add text or logos.\n\n" . $prompt;
        $boundary = 'vto-' . bin2hex(random_bytes(18));
        $body = self::field($boundary, 'model', $model)
            . self::field($boundary, 'prompt', substr($prompt, 0, 12000))
            . self::field($boundary, 'n', '1')
            . self::field($boundary, 'size', '1024x1024')
            . self::image_part($boundary, 'person', $input)
            . self::image_part($boundary, 'product', $product)
            . '--' . $boundary . "--\r\n";

        $started = self::clock();
        $response = wp_safe_remote_post(self::ENDPOINT, array(
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
        ));
        $elapsed = max(0.0, self::clock() - $started);
        unset($body, $key);
        if (is_wp_error($response)) {
            return self::transport_error($response, $elapsed, self::EDIT_TIMEOUT, true);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        if (strlen($raw) >= self::MAX_RESPONSE_BYTES) {
            return self::error('vto_provider_response_size', 'حجم پاسخ سرویس بیش از حد مجاز بود. لطفاً با پشتیبانی تماس بگیرید.', 'AvalAI response exceeded the configured maximum.');
        }
        $data = json_decode($raw, true);
        unset($raw);
        if ($status < 200 || $status > 299) {
            return self::http_error($status, $data);
        }
        if (!is_array($data) || empty($data['data'][0]['b64_json']) || !is_string($data['data'][0]['b64_json'])) {
            return self::error('vto_provider_format', 'سرویس تصویر قابل استفاده‌ای برنگرداند. پشتیبانی فروشگاه باید تنظیمات مدل را بررسی کند.', 'AvalAI did not return data[0].b64_json. Confirm that this model supports the OpenAI-compatible multi-image edits endpoint.');
        }
        $bytes = base64_decode($data['data'][0]['b64_json'], true);
        if (false === $bytes) {
            return self::error('vto_provider_image', 'تصویر خروجی سرویس قابل خواندن نیست.', 'Invalid base64 image in AvalAI response.');
        }
        $image = self::validate_image($bytes);
        if (is_wp_error($image)) {
            return self::error('vto_provider_image', 'تصویر خروجی سرویس قابل خواندن نیست.', 'AvalAI response failed image MIME, dimensions or size validation.');
        }
        return $image;
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
