<?php
/** Decode only standard image results; never persist upstream response bodies. */
namespace VTO;

defined('ABSPATH') || exit;

final class Provider_Result {
    const MAX_IMAGE_BYTES = 15728640;
    const MAX_BODY_BYTES = 23068672;
    // Image CDNs can take longer than WordPress's common 30-second default
    // after a generation has completed. This GET never submits a new edit.
    const DOWNLOAD_TIMEOUT = 110;

    /** @return array|\WP_Error Verified bytes/MIME, optionally a private recovery path. */
    public static function decode($response, array $job) {
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $type = self::content_type($response);
        $json = null;
        $json_state = 'not-parsed';
        if (strlen($body) < self::MAX_BODY_BYTES && !in_array($type, array('image/png', 'image/jpeg', 'image/webp'), true)) {
            $json = json_decode($body, true, 64);
            $json_state = self::json_state($json, json_last_error());
        }
        $shape = self::shape($status, $type, strlen($body), $json_state, $json);
        if (strlen($body) >= self::MAX_BODY_BYTES) {
            return self::error('vto_provider_format', 'حجم پاسخ سرویس بیش از حد مجاز بود؛ مدیر سایت باید گزارش درخواست را بررسی کند.', $shape . ' Response size limit reached; no automatic resubmission.');
        }
        if ($status < 200 || $status > 299) {
            return self::error('vto_provider_format', 'پاسخ سرویس قابل استفاده نیست؛ لطفاً با پشتیبانی تماس بگیرید.', $shape . ' Non-success HTTP response.');
        }
        if (in_array($type, array('image/png', 'image/jpeg', 'image/webp'), true)) {
            $image = self::image($body);
            if (!is_wp_error($image) && $image['mime'] === $type) { return $image; }
            return self::error('vto_provider_format', 'سرویس تصویر معتبری برنگرداند؛ مدیر سایت باید گزارش درخواست را بررسی کند.', $shape . ' Invalid direct image payload.');
        }
        $first = is_array($json) && isset($json['data'][0]) && is_array($json['data'][0]) ? $json['data'][0] : array();
        if (isset($first['b64_json']) && is_string($first['b64_json']) && $first['b64_json'] !== '') {
            $encoded = $first['b64_json'];
            $bytes = strlen($encoded) <= 4 * (int) ceil(self::MAX_IMAGE_BYTES / 3) ? base64_decode($encoded, true) : false;
            // Canonical comparison rejects ignored whitespace, invalid padding and
            // data-URI prefixes as well as non-base64 bytes.
            if (false !== $bytes && base64_encode($bytes) === $encoded) {
                $image = self::image($bytes);
                if (!is_wp_error($image)) { return $image; }
            }
            return self::error('vto_provider_image', 'تصویر خروجی سرویس قابل خواندن نیست؛ مدیر سایت باید گزارش درخواست را بررسی کند.', $shape . ' Invalid standard base64 image.');
        }
        if (isset($first['url']) && is_string($first['url']) && $first['url'] !== '') {
            $url = $first['url'];
            if (!self::reference_url($url)) {
                return self::error('vto_provider_result_url', 'سرویس نشانی امن و قابل استفاده‌ای برای تصویر برنگرداند؛ مدیر سایت باید گزارش درخواست را بررسی کند.', $shape . ' Standard result URL failed HTTPS/public-destination validation.');
            }
            // Preserve a provider-generated result before its first download.
            // A later GET can recover it without another paid edit request.
            $reference = Storage::save_reference($url);
            if (is_wp_error($reference)) {
                return self::error('vto_provider_reference', 'ثبت خصوصی نشانی نتیجه انجام نشد؛ برای جلوگیری از هزینهٔ تکراری با پشتیبانی تماس بگیرید.', $shape . ' Private result reference could not be saved.');
            }
            if (!empty($job['id']) && !Jobs::record_reference(absint($job['id']), $reference)) {
                Storage::delete($reference);
                return self::error('vto_provider_reference', 'وضعیت درخواست تغییر کرده است؛ با پشتیبانی تماس بگیرید.', $shape . ' Could not associate private result reference with the processing job.');
            }
            $image = self::fetch($url);
            if (is_wp_error($image)) {
                $data = $image->get_error_data();
                $data = is_array($data) ? $data : array();
                $data['detail'] = $shape . ' ' . (isset($data['detail']) ? $data['detail'] : 'Result download failed.');
                $data['recovery_path'] = $reference;
                $image->add_data($data);
                return $image;
            }
            $image['recovery_path'] = $reference;
            return $image;
        }
        $state = is_array($json) && isset($json['status']) && is_string($json['status']) ? $json['status'] : '';
        if (202 === $status || in_array($state, array('accepted', 'queued', 'pending', 'processing', 'in_progress'), true)) {
            return self::error('vto_provider_pending', 'سرویس درخواست را پذیرفته اما هنوز تصویر نهایی برنگردانده است؛ برای جلوگیری از هزینهٔ تکراری درخواست تازه‌ای نسازید و با پشتیبانی تماس بگیرید.', $shape . ' Accepted/pending response without a standard final image. No documented polling endpoint is configured; no automatic resubmission.');
        }
        return self::error('vto_provider_format', 'سرویس تصویر قابل استفاده‌ای برنگرداند. پشتیبانی فروشگاه باید گزارش امن پاسخ را بررسی کند.', $shape . ' No standard final image result. Check endpoint/model compatibility; no automatic resubmission.');
    }

    /** Download an already-generated result only. Never submits a generation. */
    public static function fetch(string $url) {
        $started = self::clock();
        for ($hop = 0; $hop <= 3; $hop++) {
            if (!self::safe_url($url)) {
                return self::error('vto_provider_result_url', 'نشانی تصویر خروجی امن یا قابل دسترسی نیست؛ با پشتیبانی تماس بگیرید.', 'Result download blocked by HTTPS/public-destination validation. No generation was submitted.');
            }
            $remaining = self::DOWNLOAD_TIMEOUT - (self::clock() - $started);
            if ($remaining <= 0) {
                return self::download_error('Result download time budget exhausted.');
            }
            // Redirects are followed manually so every destination remains HTTPS
            // and each GET starts without authentication or inherited cookies.
            $response = wp_safe_remote_get($url, array(
                'timeout' => max(1, $remaining), 'redirection' => 0, 'sslverify' => true,
                'reject_unsafe_urls' => true, 'limit_response_size' => self::MAX_IMAGE_BYTES + 1,
                'cookies' => array(), 'headers' => array('Accept' => 'image/png,image/jpeg,image/webp'),
            ));
            if (is_wp_error($response)) {
                $detail = 'Result download transport failure';
                if (preg_match('/\bcURL\s+error\s+([0-9]{1,3})\b/i', $response->get_error_message(), $match)) {
                    $detail .= '; cURL error ' . (int) $match[1];
                }
                $elapsed = max(0.0, self::clock() - $started);
                return self::download_error($detail . '; elapsed_seconds=' . number_format($elapsed, 2, '.', '') . '; configured_timeout_seconds=' . self::DOWNLOAD_TIMEOUT . '.');
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            if (in_array($status, array(301, 302, 303, 307, 308), true)) {
                $location = wp_remote_retrieve_header($response, 'location');
                if ($hop >= 3 || !is_string($location) || $location === '' || strlen($location) > 16384) {
                    return self::download_error('Result download redirect limit or invalid redirect.');
                }
                $url = \WP_Http::make_absolute_url($location, $url);
                continue;
            }
            if (200 !== $status) {
                return self::download_error('Result download HTTP ' . $status . '.');
            }
            $body = wp_remote_retrieve_body($response);
            $image = self::image($body);
            if (is_wp_error($image)) {
                return self::download_error('Result download failed image MIME, dimensions or byte-limit validation.');
            }
            return $image;
        }
        return self::download_error('Result download redirect limit reached.');
    }

    private static function safe_url(string $url): bool {
        if (!self::reference_url($url)) { return false; }
        $parts = wp_parse_url($url);
        if (!wp_http_validate_url($url)) { return false; }
        $host = rtrim($parts['host'], '.');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : gethostbynamel($host);
        if (!$addresses) { return false; }
        // WordPress permits the store's own host and some reserved networks.
        // Result retrieval intentionally requires public destinations even there.
        foreach ($addresses as $ip) { if (!self::public_ip($ip)) { return false; } }
        if (!filter_var($host, FILTER_VALIDATE_IP) && function_exists('dns_get_record') && defined('DNS_AAAA')) {
            $records = @dns_get_record($host, DNS_AAAA);
            foreach (is_array($records) ? $records : array() as $record) {
                if (!empty($record['ipv6']) && !self::public_ip($record['ipv6'])) { return false; }
            }
        }
        return true;
    }

    /** Syntactic checks only: retain a generated URL even during a DNS outage. */
    private static function reference_url(string $url): bool {
        if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)) { return false; }
        $parts = wp_parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) { return false; }
        $host = rtrim($parts['host'], '.');
        return !filter_var($host, FILTER_VALIDATE_IP) || self::public_ip($host);
    }

    private static function public_ip(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $blocked = array('0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4');
        } else {
            if (!self::in_network($ip, '2000::/3')) { return false; }
            $blocked = array('2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20');
        }
        foreach ($blocked as $network) { if (self::in_network($ip, $network)) { return false; } }
        return true;
    }

    private static function in_network(string $ip, string $network): bool {
        list($base, $bits) = explode('/', $network, 2);
        $packed = @inet_pton($ip); $range = @inet_pton($base);
        if (!$packed || !$range || strlen($packed) !== strlen($range)) { return false; }
        $bytes = intdiv((int) $bits, 8); $remaining = (int) $bits % 8;
        return substr($packed, 0, $bytes) === substr($range, 0, $bytes)
            && (!$remaining || (ord($packed[$bytes]) & (255 << (8 - $remaining))) === (ord($range[$bytes]) & (255 << (8 - $remaining))));
    }

    private static function image($bytes) {
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) { return new \WP_Error('invalid_result_image'); }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'] ?? '', array('image/png', 'image/jpeg', 'image/webp'), true)
            || $info[0] < 64 || $info[1] < 64 || $info[0] > 8000 || $info[1] > 8000 || $info[0] * $info[1] > 24000000) {
            return new \WP_Error('invalid_result_image');
        }
        return array('bytes' => $bytes, 'mime' => $info['mime']);
    }

    private static function shape($status, $type, $length, $state, $json): string {
        $top = array('created', 'data', 'error', 'usage', 'status', 'id', 'object', 'output', 'images', 'result', 'model', 'request_id', 'choices', 'message', 'detail', 'request', 'response', 'success', 'code', 'task_id');
        $item = array('b64_json', 'url', 'revised_prompt', 'status', 'error', 'message', 'detail', 'code');
        $first = is_array($json) && isset($json['data'][0]) && is_array($json['data'][0]) ? $json['data'][0] : null;
        $detail = 'AvalAI result shape: HTTP ' . (int) $status . '; content_type=' . $type . '; body_bytes=' . (int) $length . '; json=' . $state
            . '; top_keys=' . self::keys($json, $top) . '; data0_keys=' . self::keys($first, $item);
        $codes = array('invalid_api_key', 'authentication_error', 'insufficient_quota', 'rate_limit_exceeded', 'model_not_found', 'invalid_request_error', 'content_policy_violation', 'billing_hard_limit_reached', 'unsupported_model', 'invalid_value', 'image_parse_error', 'permission_denied');
        $code = is_array($json) && isset($json['error']['code']) ? $json['error']['code'] : null;
        if (in_array($code, $codes, true)) { $detail .= '; known_error_code=' . $code; }
        return $detail . '.';
    }

    private static function keys($value, array $allowed): string {
        if (!is_array($value)) { return 'not-an-object'; }
        $known = array(); $unknown = 0;
        foreach (array_keys($value) as $key) {
            if (in_array($key, $allowed, true)) { $known[] = $key; } else { $unknown++; }
        }
        sort($known);
        return '[' . implode(',', $known) . ']; unknown_keys=' . $unknown;
    }

    private static function content_type($response): string {
        $header = wp_remote_retrieve_header($response, 'content-type');
        if (!is_string($header) || $header === '') { return 'missing'; }
        $type = strtolower(trim(explode(';', $header, 2)[0]));
        return in_array($type, array('application/json', 'application/problem+json', 'text/json', 'text/html', 'text/plain', 'image/png', 'image/jpeg', 'image/webp', 'application/octet-stream'), true) ? $type : 'other';
    }

    private static function json_state($json, int $error): string {
        $states = array(JSON_ERROR_DEPTH => 'depth-error', JSON_ERROR_STATE_MISMATCH => 'state-error', JSON_ERROR_CTRL_CHAR => 'control-error', JSON_ERROR_SYNTAX => 'syntax-error', JSON_ERROR_UTF8 => 'utf8-error');
        if ($error !== JSON_ERROR_NONE) { return $states[$error] ?? 'parse-error'; }
        if (is_array($json)) { return array_is_list($json) ? 'valid-array' : 'valid-object'; }
        return 'valid-scalar';
    }

    private static function clock() { return function_exists('hrtime') ? hrtime(true) / 1000000000 : microtime(true); }

    private static function download_error(string $detail) {
        return self::error('vto_provider_download', 'دریافت تصویر آماده‌شده کامل نشد؛ مدیر سایت می‌تواند دریافت همین نتیجه را بدون ساخت دوباره امتحان کند.', $detail . ' No new generation was submitted.');
    }

    private static function error(string $code, string $message, string $detail) { return new \WP_Error($code, $message, array('detail' => $detail)); }
}
