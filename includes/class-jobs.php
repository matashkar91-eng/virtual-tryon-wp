<?php
namespace VTO;
defined('ABSPATH') || exit;

final class Jobs {
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'vto_jobs'; }
    private static function rates(): string { global $wpdb; return $wpdb->prefix . 'vto_rates'; }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash varchar(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            product_id varchar(100) NOT NULL,
            product_name varchar(255) NOT NULL,
            product_image text NOT NULL,
            product_image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            category varchar(40) NOT NULL,
            mode varchar(20) NOT NULL,
            model varchar(190) NOT NULL,
            prompt text NOT NULL,
            input_path text NOT NULL,
            output_path text NOT NULL,
            reference_path text NULL,
            error_code varchar(100) NOT NULL DEFAULT '',
            error_message text NOT NULL,
            error_detail text NOT NULL,
            attempts int unsigned NOT NULL DEFAULT 0,
            recovery_attempts int unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY status (status),
            KEY expires_at (expires_at)
        ) $collate;");
        $rates = self::rates();
        dbDelta("CREATE TABLE $rates (
            bucket varchar(64) NOT NULL,
            hits int unsigned NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (bucket),
            KEY expires_at (expires_at)
        ) $collate;");
        update_option('vto_db_version', VTO_VERSION, false);
        self::schedule();
    }

    public static function init(): void {
        if (get_option('vto_db_version') !== VTO_VERSION) { self::install(); }
        add_action('vto_process_job', [self::class, 'process'], 10, 1);
        add_action('vto_maintenance', [self::class, 'maintenance']);
        self::schedule();
    }

    private static function schedule(): void {
        if (!wp_next_scheduled('vto_maintenance')) { wp_schedule_event(time() + 60, 'hourly', 'vto_maintenance'); }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('vto_maintenance');
        wp_unschedule_hook('vto_process_job');
    }

    /** Atomic fixed-window budget; no client-supplied forwarded-IP trust. */
    private static function consume(string $identity, int $limit): bool {
        global $wpdb;
        $table = self::rates();
        $bucket = hash_hmac('sha256', $identity . ':' . gmdate('Y-m-d-H'), wp_salt('nonce'));
        $expiry = gmdate('Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS);
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (bucket,hits,expires_at) VALUES (%s,0,%s)", $bucket, $expiry));
        return $wpdb->query($wpdb->prepare("UPDATE $table SET hits = hits + 1 WHERE bucket = %s AND hits < %d", $bucket, $limit)) === 1;
    }

    public static function budget() {
        $settings = Settings::get();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        // A trusted reverse proxy may supply a verified identity via this server-side filter.
        $identity = (string) apply_filters('vto_rate_identity', $ip);
        if (!self::consume('ip:' . $identity, max(1, (int) $settings['rate_limit']))) {
            return new \WP_Error('rate_limit', 'تعداد درخواست‌های شما به سقف ساعتی رسیده است. کمی بعد دوباره تلاش کنید.', ['status' => 429]);
        }
        if (!self::consume('global', max(1, (int) ($settings['global_rate_limit'] ?? 50)))) {
            return new \WP_Error('store_limit', 'ظرفیت فعلی فروشگاه تکمیل است؛ لطفاً بعداً تلاش کنید.', ['status' => 429]);
        }
        return true;
    }

    public static function create(array $product, string $input) {
        global $wpdb;
        $settings = Settings::get();
        $token = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'token_hash' => hash('sha256', $token), 'status' => 'queued',
            'product_id' => $product['id'], 'product_name' => $product['name'],
            'product_image' => $product['image'], 'product_image_id' => $product['image_id'],
            'category' => $product['category'], 'mode' => $settings['mode'], 'model' => $settings['model'],
            'prompt' => strtr($settings['prompt'], ['{product}' => $product['name'], '{category}' => $product['category']]),
            'input_path' => $input, 'output_path' => '', 'reference_path' => '', 'error_message' => '', 'error_detail' => '',
            'created_at' => $now, 'updated_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + max(1, (int) $settings['retention_hours']) * HOUR_IN_SECONDS),
        ];
        if (!$wpdb->insert(self::table(), $row)) {
            Storage::delete($input);
            return new \WP_Error('queue_unavailable', 'ثبت درخواست انجام نشد؛ دوباره تلاش کنید.', ['status' => 503]);
        }
        $id = (int) $wpdb->insert_id;
        wp_schedule_single_event(time() + 1, 'vto_process_job', [$id]);
        return ['id' => $id, 'token' => $token, 'status' => 'queued'];
    }

    public static function by_id(int $id): ?array {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A) ?: null;
    }

    public static function by_token(string $token): ?array {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { return null; }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE token_hash = %s AND expires_at > %s', hash('sha256', $token), gmdate('Y-m-d H:i:s')), ARRAY_A) ?: null;
    }

    public static function list_jobs(int $limit = 50): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', min(100, max(1, $limit))), ARRAY_A) ?: [];
    }

    public static function process($id): void {
        global $wpdb;
        $id = absint($id);
        $table = self::table();
        // One process wins the lease. Polling never calls the AI provider.
        $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET status='processing', attempts=attempts+1, updated_at=%s WHERE id=%d AND status='queued' AND expires_at > %s", gmdate('Y-m-d H:i:s'), $id, gmdate('Y-m-d H:i:s')));
        if ($claimed !== 1) { return; }
        $job = self::by_id($id);
        try {
            if (!$job || !Storage::owns($job['input_path'])) {
                $result = new \WP_Error('input_missing', 'عکس ورودی موجود نیست؛ درخواست جدید ثبت کنید.');
            } else {
                $result = Provider::generate($job);
            }
            if (!is_wp_error($result)) { $result = Storage::save($result['bytes']); }
            $data = ['updated_at' => gmdate('Y-m-d H:i:s')];
            if (is_wp_error($result)) {
                $data += ['status' => 'failed', 'error_code' => sanitize_key($result->get_error_code()), 'error_message' => sanitize_text_field($result->get_error_message())];
                $detail = $result->get_error_data();
                $data['error_detail'] = is_array($detail) && isset($detail['detail']) ? substr(sanitize_text_field($detail['detail']), 0, 1000) : '';
            } else {
                $data += ['status' => 'completed', 'output_path' => $result, 'error_code' => '', 'error_message' => '', 'error_detail' => ''];
            }
            $updated = $wpdb->update($table, $data, ['id' => $id, 'status' => 'processing']);
            if (!$updated && !is_wp_error($result)) { Storage::delete($result); }
            if ($updated && !is_wp_error($result)) { self::clear_reference($id); }
        } catch (\Throwable $e) {
            // Never persist exception text: SDK errors can contain secrets or image data.
            $wpdb->update($table, ['status' => 'failed', 'error_code' => 'worker_error', 'error_message' => 'پردازش انجام نشد. مدیر سایت می‌تواند وضعیت اتصال را بررسی کند.', 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id, 'status' => 'processing']);
        }
    }

    /** Commit a private result reference before its network download starts. */
    public static function record_reference(int $id, string $path): bool {
        global $wpdb;
        if (is_wp_error(Storage::read_reference($path))) { return false; }
        return $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET reference_path=%s WHERE id=%d AND status='processing' AND expires_at > %s AND (reference_path IS NULL OR reference_path='')", $path, $id, gmdate('Y-m-d H:i:s'))) === 1;
    }

    private static function clear_reference(int $id): void {
        global $wpdb;
        $job = self::by_id($id);
        if ($job && !empty($job['reference_path'])) {
            Storage::delete($job['reference_path']);
            $wpdb->update(self::table(), ['reference_path' => ''], ['id' => $id, 'status' => 'completed']);
        }
    }

    /** Download a previously produced result. This path never calls generate(). */
    public static function recover(int $id) {
        global $wpdb;
        $job = self::by_id($id);
        if (!$job || $job['status'] !== 'failed' || empty($job['reference_path']) || $job['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            return new \WP_Error('recovery_unavailable', 'برای این درخواست نشانی نتیجهٔ قابل دریافت وجود ندارد یا زمان نگهداری تمام شده است.');
        }
        $url = Storage::read_reference($job['reference_path']);
        if (is_wp_error($url)) { return $url; }
        $table = self::table();
        $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET status='processing', recovery_attempts=recovery_attempts+1, updated_at=%s WHERE id=%d AND status='failed' AND recovery_attempts < 3 AND expires_at > %s", gmdate('Y-m-d H:i:s'), $id, gmdate('Y-m-d H:i:s')));
        if ($claimed !== 1) { return new \WP_Error('recovery_limit', 'وضعیت درخواست تغییر کرده یا سقف سه تلاش برای دریافت نتیجه تکمیل شده است.'); }
        try {
            $result = Provider_Result::fetch($url);
            if (!is_wp_error($result)) { $result = Storage::save($result['bytes']); }
            $data = ['updated_at' => gmdate('Y-m-d H:i:s')];
            if (is_wp_error($result)) {
                $detail = $result->get_error_data();
                $data += ['status' => 'failed', 'error_code' => sanitize_key($result->get_error_code()), 'error_message' => sanitize_text_field($result->get_error_message()), 'error_detail' => is_array($detail) && isset($detail['detail']) ? substr(sanitize_text_field($detail['detail']), 0, 1000) : ''];
            } else {
                $data += ['status' => 'completed', 'output_path' => $result, 'error_code' => '', 'error_message' => '', 'error_detail' => ''];
            }
            $updated = $wpdb->update($table, $data, ['id' => $id, 'status' => 'processing']);
            if (!$updated) {
                if (!is_wp_error($result)) { Storage::delete($result); }
                return new \WP_Error('recovery_conflict', 'وضعیت درخواست تغییر کرده است.');
            }
            if (is_wp_error($result)) { return $result; }
            self::clear_reference($id);
            return true;
        } catch (\Throwable $error) {
            $wpdb->update($table, ['status' => 'failed', 'error_code' => 'recovery_error', 'error_message' => 'دریافت نتیجه کامل نشد؛ پشتیبانی می‌تواند اتصال را بررسی کند.', 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id, 'status' => 'processing']);
            return new \WP_Error('recovery_error', 'دریافت نتیجه کامل نشد؛ هیچ درخواست ساخت تازه‌ای ارسال نشد.');
        }
    }

    public static function retry(int $id) {
        global $wpdb;
        $job = self::by_id($id);
        if (!$job || $job['status'] !== 'failed' || $job['expires_at'] <= gmdate('Y-m-d H:i:s') || !Storage::owns($job['input_path'])) {
            return new \WP_Error('retry_unavailable', 'فقط درخواست ناموفق و منقضی‌نشده با عکس موجود قابل تکرار است.');
        }
        if ((int) $job['attempts'] >= 3) { return new \WP_Error('retry_limit', 'سقف سه تلاش برای این درخواست تکمیل شده است.'); }
        if (!empty($job['reference_path'])) {
            return new \WP_Error('result_available', 'نشانی نتیجهٔ قبلی ذخیره شده است؛ ابتدا از «دریافت دوبارهٔ نتیجه» استفاده کنید.');
        }
        if (self::consume('global', max(1, (int) (Settings::get()['global_rate_limit'] ?? 50))) === false) {
            return new \WP_Error('store_limit', 'سقف ساعتی فروشگاه تکمیل است.');
        }
        $changed = $wpdb->update(self::table(), ['status' => 'queued', 'error_code' => '', 'error_message' => '', 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id, 'status' => 'failed']);
        if (!$changed) { return new \WP_Error('retry_conflict', 'وضعیت درخواست تغییر کرده است.'); }
        wp_schedule_single_event(time() + 1, 'vto_process_job', [$id]);
        return true;
    }

    public static function delete(int $id) {
        global $wpdb;
        $job = self::by_id($id);
        if (!$job) { return new \WP_Error('not_found', 'درخواست موجود نیست.'); }
        if ($job['status'] === 'processing') { return new \WP_Error('busy', 'تا پایان پردازش صبر کنید.'); }
        if ($wpdb->delete(self::table(), ['id' => $id, 'status' => $job['status']]) !== 1) { return new \WP_Error('busy', 'وضعیت درخواست تغییر کرده است.'); }
        Storage::delete($job['input_path']);
        Storage::delete($job['output_path']);
        Storage::delete((string) ($job['reference_path'] ?? ''));
        wp_clear_scheduled_hook('vto_process_job', [$id]);
        return true;
    }

    public static function maintenance(): void {
        global $wpdb;
        $table = self::table();
        $now = gmdate('Y-m-d H:i:s');
        // An interrupted external request has an uncertain billing outcome: no automatic retry.
        $wpdb->query($wpdb->prepare("UPDATE $table SET status='failed', error_code='worker_interrupted', error_message=%s, updated_at=%s WHERE status='processing' AND updated_at < %s", 'پردازش قطع شده؛ پیش از تکرار، وضعیت مصرف سرویس بررسی شود.', $now, gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS)));
        $expired = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE expires_at <= %s AND status <> 'processing' LIMIT 500", $now));
        foreach ($expired as $id) { self::delete((int) $id); }
        $paths = $wpdb->get_results("SELECT input_path, output_path, reference_path FROM $table", ARRAY_A);
        $retained = [];
        foreach ($paths as $paths_row) {
            $retained[] = $paths_row['input_path'];
            $retained[] = $paths_row['output_path'];
            $retained[] = (string) ($paths_row['reference_path'] ?? '');
        }
        Storage::sweep_orphans($retained);
        $queued = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE status='queued' AND expires_at > %s ORDER BY id ASC LIMIT 20", $now));
        foreach ($queued as $id) {
            if (!wp_next_scheduled('vto_process_job', [(int) $id])) { wp_schedule_single_event(time() + 1, 'vto_process_job', [(int) $id]); }
        }
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::rates() . ' WHERE expires_at < %s', $now));
    }
}
