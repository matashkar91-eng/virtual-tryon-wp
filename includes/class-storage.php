<?php
namespace VTO;
defined('ABSPATH') || exit;

final class Storage {
    /** Fail closed: personal images must never reside in a web-served directory. */
    public static function directory() {
        $path = defined('VTO_PRIVATE_DIR') ? VTO_PRIVATE_DIR : dirname(untrailingslashit(ABSPATH)) . '/vto-private-' . substr(hash('sha256', home_url()), 0, 12);
        if (!is_string($path) || !path_is_absolute($path) || !wp_mkdir_p($path)) {
            return new \WP_Error('storage_unavailable', 'پوشهٔ خصوصی تصاویر قابل نوشتن نیست. مدیر سایت باید VTO_PRIVATE_DIR را تنظیم کند.');
        }
        $real = realpath($path);
        $roots = [ABSPATH, WP_CONTENT_DIR, $_SERVER['DOCUMENT_ROOT'] ?? ''];
        // Cron/CLI often has no DOCUMENT_ROOT. Infer the public root for a
        // conventional /public_html/wordpress subdirectory installation too.
        $site_path = untrailingslashit((string) wp_parse_url(site_url(), PHP_URL_PATH));
        $wp_root = wp_normalize_path(untrailingslashit(ABSPATH));
        if ($site_path && $site_path !== '/' && str_ends_with($wp_root, $site_path)) {
            $roots[] = substr($wp_root, 0, -strlen($site_path));
        }
        $uploads = wp_upload_dir();
        $roots[] = $uploads['basedir'];
        foreach ($roots as $root) {
            if (!$root || !realpath($root)) { continue; }
            $root = rtrim(wp_normalize_path(realpath($root)), '/') . '/';
            if (str_starts_with(rtrim(wp_normalize_path($real), '/') . '/', $root)) {
                return new \WP_Error('storage_public', 'پوشهٔ تصاویر باید خارج از ریشهٔ عمومی وب‌سایت باشد.');
            }
        }
        @chmod($real, 0700);
        return $real;
    }

    public static function validate(string $file, int $limit) {
        if (!is_file($file) || filesize($file) < 1 || filesize($file) > $limit) {
            return new \WP_Error('image_size', 'حجم تصویر بیشتر از حد مجاز است یا فایل خالی است.');
        }
        $info = @getimagesize($file);
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return new \WP_Error('image_type', 'فقط تصویر معتبر JPG، PNG یا WebP پذیرفته می‌شود.');
        }
        if ($info[0] < 64 || $info[1] < 64 || $info[0] > 8000 || $info[1] > 8000 || $info[0] * $info[1] > 24000000) {
            return new \WP_Error('image_dimensions', 'تصویر باید حداقل ۶۴ پیکسل و حداکثر ۲۴ مگاپیکسل باشد.');
        }
        return $info;
    }

    public static function upload(array $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return new \WP_Error('upload_failed', 'بارگذاری عکس کامل نشد. دوباره تلاش کنید.');
        }
        $valid = self::validate($file['tmp_name'], min(20, max(1, (int) Settings::get()['max_upload_mb'])) * MB_IN_BYTES);
        if (is_wp_error($valid)) { return $valid; }
        $dir = self::directory();
        if (is_wp_error($dir)) { return $dir; }
        // Re-encode to strip location metadata and reject embedded executable payloads.
        $editor = wp_get_image_editor($file['tmp_name']);
        if (is_wp_error($editor)) {
            return new \WP_Error('image_editor', 'پردازش امن تصویر ممکن نیست؛ مدیر سایت باید GD یا Imagick را فعال کند.');
        }
        $editor->maybe_exif_rotate();
        $editor->resize(2048, 2048, false);
        $editor->set_quality(90);
        $path = $dir . '/' . bin2hex(random_bytes(24)) . '.jpg';
        $saved = $editor->save($path, 'image/jpeg');
        if (is_wp_error($saved)) { return new \WP_Error('image_save', 'ذخیرهٔ امن تصویر انجام نشد.'); }
        @chmod($path, 0600);
        return $path;
    }

    public static function save(string $bytes) {
        if (strlen($bytes) > 20 * MB_IN_BYTES) { return new \WP_Error('output_size', 'حجم تصویر خروجی بیش از حد مجاز است.'); }
        $dir = self::directory();
        if (is_wp_error($dir)) { return $dir; }
        $path = $dir . '/' . bin2hex(random_bytes(24)) . '.image';
        if (file_put_contents($path, $bytes, LOCK_EX) === false) { return new \WP_Error('output_save', 'ذخیرهٔ نتیجه انجام نشد.'); }
        @chmod($path, 0600);
        $valid = self::validate($path, 20 * MB_IN_BYTES);
        if (is_wp_error($valid)) { self::delete($path); return $valid; }
        return $path;
    }

    public static function owns(string $path): bool {
        $dir = self::directory();
        return !is_wp_error($dir) && is_file($path) && dirname(realpath($path)) === $dir;
    }

    public static function delete(string $path): void {
        if ($path && self::owns($path)) { wp_delete_file($path); }
    }

    /** Recover files left by interrupted uploads or workers before DB commit. */
    public static function sweep_orphans(array $retained): void {
        $dir = self::directory();
        if (is_wp_error($dir)) { return; }
        $keep = array_fill_keys($retained, true);
        foreach (new \DirectoryIterator($dir) as $file) {
            if (!$file->isFile() || $file->isLink() || !preg_match('/^[a-f0-9]{48}\.(jpg|image)$/D', $file->getFilename())) { continue; }
            $path = $file->getPathname();
            if (!isset($keep[$path]) && $file->getMTime() < time() - HOUR_IN_SECONDS) { self::delete($path); }
        }
    }
}
