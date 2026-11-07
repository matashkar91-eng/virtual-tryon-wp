<?php
namespace VTO;
defined('ABSPATH') || exit;

final class Rest {
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        // Unlike a public REST request, admin-ajax retains cookie authentication
        // without requiring the stale nonce we are trying to refresh.
        add_action('wp_ajax_vto_session', [self::class, 'session']);
        add_action('wp_ajax_nopriv_vto_session', [self::class, 'session']);
        add_filter('rest_post_dispatch', static function ($response, $server, $request) {
            if (str_starts_with($request->get_route(), '/vto/v1/')) {
                $response->header('Cache-Control', 'private, no-store, max-age=0');
                $response->header('Referrer-Policy', 'no-referrer');
                $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
            }
            return $response;
        }, 10, 3);
        add_action('template_redirect', static function () {
            if (isset($_GET['vto_job'])) {
                nocache_headers();
                header('Referrer-Policy: no-referrer');
                header('X-Robots-Tag: noindex, nofollow, noarchive');
            }
        });
    }

    public static function session(): void {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        wp_send_json(['nonce' => wp_create_nonce('wp_rest')]);
    }

    public static function routes(): void {
        register_rest_route('vto/v1', '/session', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => static function () { return self::response(['nonce' => wp_create_nonce('wp_rest')]); },
        ]);
        register_rest_route('vto/v1', '/jobs', [
            'methods' => 'POST', 'permission_callback' => [self::class, 'can_create'],
            'callback' => [self::class, 'create'],
        ]);
        register_rest_route('vto/v1', '/jobs/(?P<token>[a-f0-9]{64})', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => [self::class, 'status'],
        ]);
        register_rest_route('vto/v1', '/jobs/(?P<token>[a-f0-9]{64})/image', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => [self::class, 'image'],
        ]);
        register_rest_route('vto/v1', '/admin/jobs/(?P<id>\d+)/image', [
            'methods' => 'GET',
            'permission_callback' => static function () { return current_user_can('manage_options'); },
            'callback' => [self::class, 'admin_image'],
        ]);
        register_rest_route('vto/v1', '/admin/jobs/(?P<id>\d+)/provider-image', [
            'methods' => 'GET',
            'permission_callback' => static function () { return current_user_can('manage_options'); },
            'callback' => [self::class, 'admin_provider_image'],
        ]);
    }

    public static function can_create(\WP_REST_Request $request) {
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce') ?: $request->get_param('nonce'), 'wp_rest')) {
            return new \WP_Error('invalid_nonce', 'صفحه را تازه کنید و دوباره تلاش کنید.', ['status' => 403]);
        }
        if (!empty(Settings::get()['require_login']) && !is_user_logged_in()) {
            return new \WP_Error('login_required', 'برای استفاده ابتدا وارد حساب کاربری شوید.', ['status' => 401]);
        }
        $origin = $request->get_header('origin');
        $allowed = [self::origin(home_url()), self::origin(site_url())];
        if ($origin && !in_array(self::origin($origin), $allowed, true)) {
            return new \WP_Error('origin_forbidden', 'این درخواست از صفحهٔ فروشگاه ارسال نشده است.', ['status' => 403]);
        }
        return true;
    }

    private static function origin(string $url): string {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) { return ''; }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    public static function create(\WP_REST_Request $request) {
        if ($request->get_param('consent') !== '1') {
            return new \WP_Error('consent_required', 'برای پردازش عکس باید رضایت خود را اعلام کنید.', ['status' => 400]);
        }
        $product = Catalog::find(sanitize_text_field((string) $request->get_param('product_id')));
        if (!$product) { return new \WP_Error('invalid_product', 'این محصول برای پرو مجازی فعال نیست.', ['status' => 400]); }
        $files = $request->get_file_params();
        if (empty($files['image']) || !is_array($files['image'])) { return new \WP_Error('image_required', 'یک عکس انتخاب کنید.', ['status' => 400]); }
        $budget = Jobs::budget();
        if (is_wp_error($budget)) { return $budget; }
        $input = Storage::upload($files['image']);
        if (is_wp_error($input)) { $input->add_data(['status' => 400]); return $input; }
        $job = Jobs::create($product, $input);
        if (is_wp_error($job)) { return $job; }
        $page = (string) $request->get_param('page_url');
        // Never turn a tracking URL into an open redirect or third-party token leak.
        if (self::origin($page) !== self::origin(home_url())) { $page = home_url('/'); }
        $page = remove_query_arg('vto_job', strtok($page, '#'));
        $job['tracking_url'] = add_query_arg('vto_job', $job['token'], $page);
        unset($job['id']);
        return self::response($job, 202);
    }

    public static function status(\WP_REST_Request $request) {
        $job = Jobs::by_token($request['token']);
        if (!$job) { return new \WP_Error('not_found', 'درخواست موجود نیست یا زمان نگهداری آن پایان یافته است.', ['status' => 404]); }
        $remote_ready = !is_wp_error(self::provider_reference($job));
        $public_status = $remote_ready ? 'completed' : $job['status'];
        $messages = [
            'queued' => 'درخواست در صف است. می‌توانید بعداً با همین لینک نتیجه را ببینید.',
            'processing' => 'تصویر در حال پردازش است؛ این صفحه را دوباره ارسال نکنید.',
            'completed' => $job['mode'] === 'demo' ? 'آزمایش مسیر انجام شد؛ این تصویر اصلی شماست و پرو واقعی انجام نشده است.' : 'تصویر آماده است.',
            'failed' => $job['error_message'],
        ];
        if ($remote_ready) { $messages['completed'] = 'تصویر در سرویس آماده است و مستقیماً در مرورگر شما دریافت می‌شود.'; }
        $image = $public_status === 'completed' ? rest_url('vto/v1/jobs/' . $request['token'] . '/image') : null;
        return self::response([
            'status' => $public_status, 'message' => $messages[$public_status] ?? '',
            'mode' => $job['mode'], 'image_url' => $image, 'error_code' => $job['error_code'],
            'expires_at' => gmdate('c', strtotime($job['expires_at'] . ' UTC')),
        ]);
    }

    public static function image(\WP_REST_Request $request) {
        $job = Jobs::by_token($request['token']);
        if (!$job) { return new \WP_Error('not_found', 'تصویر موجود نیست یا منقضی شده است.', ['status' => 404]); }
        $reference = self::provider_reference($job);
        if (!is_wp_error($reference)) { return self::provider_redirect($reference); }
        if ($job['status'] !== 'completed') { return new \WP_Error('not_found', 'تصویر موجود نیست یا منقضی شده است.', ['status' => 404]); }
        return self::stream($job['output_path'], !empty($request['download']));
    }

    public static function admin_image(\WP_REST_Request $request) {
        $job = Jobs::by_id(absint($request['id']));
        if (!$job || $job['expires_at'] <= gmdate('Y-m-d H:i:s')) { return new \WP_Error('not_found', 'تصویر موجود نیست یا منقضی شده است.', ['status' => 404]); }
        return self::stream($request['kind'] === 'output' ? $job['output_path'] : $job['input_path'], false);
    }

    public static function admin_provider_image(\WP_REST_Request $request) {
        $job = Jobs::by_id(absint($request['id']));
        if (!$job) { return new \WP_Error('not_found', 'خروجی مستقیم موجود نیست یا منقضی شده است.', ['status' => 404]); }
        $reference = self::provider_reference($job);
        return is_wp_error($reference) ? $reference : self::provider_redirect($reference);
    }

    /** A tracking token or administrator authorization is required before this is called. */
    private static function provider_reference(array $job) {
        if (($job['status'] ?? '') !== 'failed' || empty($job['reference_path'])
            || ($job['expires_at'] ?? '') <= gmdate('Y-m-d H:i:s')) {
            return new \WP_Error('not_found', 'خروجی مستقیم موجود نیست یا منقضی شده است.', ['status' => 404]);
        }
        $url = Storage::read_reference((string) $job['reference_path']);
        if (is_wp_error($url) || !Provider_Result::valid_reference_url((string) $url)) {
            return new \WP_Error('not_found', 'خروجی مستقیم موجود نیست یا منقضی شده است.', ['status' => 404]);
        }
        return $url;
    }

    /** Let the authorized browser fetch the existing signed result when the host cannot. */
    private static function provider_redirect(string $url): \WP_REST_Response {
        $response = new \WP_REST_Response(null, 302);
        $response->header('Location', $url);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');
        $response->header('Referrer-Policy', 'no-referrer');
        $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private static function stream(string $path, bool $download) {
        if (!$path || !Storage::owns($path)) { return new \WP_Error('not_found', 'تصویر در دسترس نیست.', ['status' => 404]); }
        $valid = Storage::validate($path, 20 * MB_IN_BYTES);
        if (is_wp_error($valid)) { return new \WP_Error('not_found', 'تصویر در دسترس نیست.', ['status' => 404]); }
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: ' . $valid['mime']);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="try-on.' . ($valid['mime'] === 'image/png' ? 'png' : ($valid['mime'] === 'image/webp' ? 'webp' : 'jpg')) . '"');
        readfile($path);
        exit;
    }

    private static function response(array $data, int $status = 200): \WP_REST_Response {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Referrer-Policy', 'no-referrer');
        $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }
}
