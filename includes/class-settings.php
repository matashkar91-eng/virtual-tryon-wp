<?php
namespace VTO;

defined('ABSPATH') || exit;

/** Store-specific configuration. API credentials never enter public settings. */
final class Settings {
    const OPTION = 'vto_settings';

    public static function defaults() {
        return array(
            'mode' => 'demo',
            'api_key' => '',
            'model' => 'gpt-image-2.5-flare',
            'quality' => 'low',
            'category' => 'auto',
            'garment_type' => 'scarf',
            'catalog_source' => 'designs',
            'prompt' => 'Create a realistic virtual try-on of {product} ({category}) on the person in the input photo. Preserve the person, face, body proportions, pose, lighting and background. Change only the selected garment or accessory. Preserve its material, pattern and colors. Use a clean, natural result.',
            'title' => 'قبل از خرید، روی خودت ببین',
            'subtitle' => 'مدل مورد علاقه‌ات را انتخاب کن، یک عکس واضح بفرست و نتیجهٔ پرو مجازی را ببین.',
            'product_label' => 'روسری‌ات را انتخاب کن',
            'upload_label' => 'عکس خودت را اضافه کن',
            'button_label' => 'ساخت تصویر پرو مجازی',
            'success_message' => 'تصویرت آماده است؛ حالا راحت‌تر انتخاب کن.',
            'consent_text' => 'اجازه می‌دهم این تصویر برای ساخت پرو مجازی به سرویس هوش مصنوعی ارسال شود. حق استفاده از این عکس را دارم و سیاست حریم خصوصی فروشگاه را خوانده‌ام.',
            'accent_color' => '#6941c6',
            'bg_color' => '#faf8ff',
            'text_color' => '#211b32',
            'banner_id' => 0,
            'logo_id' => 0,
            'require_login' => false,
            'max_upload_mb' => 8,
            'rate_limit' => 5,
            'global_rate_limit' => 50,
            'retention_hours' => 24,
            'product_ids' => array(),
            'manual_products' => array(),
            'labels' => array(),
        );
    }

    public static function get() {
        $stored = get_option(self::OPTION, array());
        return array_merge(self::defaults(), is_array($stored) ? $stored : array());
    }

    public static function register() {
        // Keep a credential-bearing option out of WordPress's autoload cache.
        add_option(self::OPTION, self::defaults(), '', false);
        if (function_exists('wp_set_option_autoload')) {
            wp_set_option_autoload(self::OPTION, false);
        }
        register_setting('vto_settings_group', self::OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize'),
            'show_in_rest' => false,
        ));
    }

    public static function valid_model(string $model): bool {
        return strlen($model) <= 160
            && preg_match('~\A[A-Za-z0-9][A-Za-z0-9_.:-]*(?:/[A-Za-z0-9][A-Za-z0-9_.:-]*){0,4}\z~', $model) === 1;
    }

    public static function valid_quality($quality): bool {
        return is_string($quality) && in_array($quality, array('low', 'medium', 'high', 'auto'), true);
    }

    public static function sanitize($input) {
        $old = self::get();
        if (!current_user_can('manage_options') || !is_array($input)) {
            return $old;
        }
        // Settings and artwork-library forms save the same option independently.
        // Omitted fields must never reset credentials, cost, privacy or designs.
        $out = $old;
        $enums = array(
            'mode' => array('demo', 'avalai'),
            'category' => array('auto', 'tops', 'bottoms', 'one-pieces'),
            'garment_type' => array('scarf', 'clothing', 'glasses', 'jewelry'),
            'catalog_source' => array('designs', 'woocommerce'),
        );
        foreach ($enums as $key => $values) {
            if (isset($input[$key]) && in_array($input[$key], $values, true)) {
                $out[$key] = $input[$key];
            }
        }
        // Missing or malformed input must not silently change the merchant's saved cost profile.
        $out['quality'] = self::valid_quality(isset($old['quality']) ? $old['quality'] : null) ? $old['quality'] : self::defaults()['quality'];
        if (isset($input['quality']) && self::valid_quality($input['quality'])) {
            $out['quality'] = $input['quality'];
        }
        foreach (array('title', 'product_label', 'upload_label', 'button_label', 'success_message') as $key) {
            $out[$key] = isset($input[$key]) ? sanitize_text_field($input[$key]) : $old[$key];
        }
        foreach (array('subtitle', 'consent_text', 'prompt') as $key) {
            $out[$key] = isset($input[$key]) ? sanitize_textarea_field($input[$key]) : $old[$key];
        }
        foreach (array('accent_color', 'bg_color', 'text_color') as $key) {
            $color = isset($input[$key]) ? sanitize_hex_color($input[$key]) : null;
            $out[$key] = $color ? $color : $old[$key];
        }
        foreach (array('banner_id', 'logo_id') as $key) {
            $id = isset($input[$key]) ? absint($input[$key]) : absint($old[$key]);
            $out[$key] = $id && wp_attachment_is_image($id) ? $id : 0;
        }
        $out['require_login'] = array_key_exists('require_login', $input) ? !empty($input['require_login']) : !empty($old['require_login']);
        $out['max_upload_mb'] = max(1, min(12, absint(isset($input['max_upload_mb']) ? $input['max_upload_mb'] : $old['max_upload_mb'])));
        $out['rate_limit'] = max(1, min(50, absint(isset($input['rate_limit']) ? $input['rate_limit'] : $old['rate_limit'])));
        $out['global_rate_limit'] = max(1, min(1000, absint(isset($input['global_rate_limit']) ? $input['global_rate_limit'] : $old['global_rate_limit'])));
        $out['retention_hours'] = max(1, min(168, absint(isset($input['retention_hours']) ? $input['retention_hours'] : $old['retention_hours'])));
        $model = isset($input['model']) && is_scalar($input['model']) ? trim($input['model']) : $old['model'];
        if (self::valid_model($model)) {
            $out['model'] = $model;
        } else {
            $out['model'] = $old['model'];
            add_settings_error(self::OPTION, 'vto_model', 'شناسهٔ مدل معتبر نیست. فقط نام مدل، مانند gpt-image-2.5-flare، را بدون آدرس وب و فاصله وارد کنید (حداکثر ۱۶۰ نویسه).');
        }
        // A blank field preserves the saved credential; clearing is explicit.
        $out['api_key'] = $old['api_key'];
        if (!empty($input['clear_api_key'])) {
            $out['api_key'] = '';
        } elseif (!empty($input['api_key']) && is_scalar($input['api_key'])) {
            $out['api_key'] = preg_replace('/[\r\n\x00]/', '', trim($input['api_key']));
        }
        $ids = isset($input['product_ids']) ? $input['product_ids'] : $old['product_ids'];
        if (!is_array($ids) && is_scalar($ids)) {
            $ids = preg_split('/[\s,،]+/u', $ids, -1, PREG_SPLIT_NO_EMPTY);
        }
        $ids = is_array($ids) ? array_filter($ids, 'is_scalar') : array();
        $out['product_ids'] = array_values(array_unique(array_filter(array_map('absint', $ids))));
        $save_designs = array_key_exists('manual_products', $input) || !empty($input['manual_products_present']);
        $manual = $save_designs ? (isset($input['manual_products']) && is_array($input['manual_products']) ? $input['manual_products'] : array()) : (array) $old['manual_products'];
        $out['manual_products'] = array();
        $seen = array();
        foreach (array_slice($manual, 0, 100) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $image_id = isset($row['image_id']) ? absint($row['image_id']) : 0;
            $name = isset($row['name']) ? sanitize_text_field($row['name']) : '';
            if (!$name || !$image_id || !wp_attachment_is_image($image_id)) {
                continue;
            }
            $id = !empty($row['id']) ? sanitize_key($row['id']) : 'item-' . $image_id;
            if (!preg_match('/^[a-z0-9_-]{1,80}$/D', $id) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $category = isset($row['category']) && in_array($row['category'], $enums['category'], true) ? $row['category'] : 'auto';
            $active = !array_key_exists('active', $row) || !empty($row['active']);
            $out['manual_products'][] = array('id' => $id, 'name' => $name, 'image_id' => $image_id, 'category' => $category, 'active' => $active);
        }
        foreach (array_keys(self::label_names()) as $key) {
            if (isset($input['labels'][$key]) && is_scalar($input['labels'][$key])) {
                $label = sanitize_textarea_field($input['labels'][$key]);
                if ($label !== '') {
                    $out['labels'][$key] = $label;
                } else {
                    unset($out['labels'][$key]);
                }
            }
        }
        return $out;
    }

    public static function label_names() {
        return array(
            'eyebrow' => 'متن کوتاه بالای عنوان',
            'step_product' => 'عنوان مرحلهٔ انتخاب محصول',
            'step_upload' => 'عنوان مرحلهٔ بارگذاری عکس',
            'step_result' => 'عنوان مرحلهٔ نتیجه',
            'photo_hint' => 'راهنمای عکس مناسب',
            'choose_photo' => 'دکمهٔ انتخاب عکس',
            'replace_photo' => 'دکمهٔ تعویض عکس',
            'remove_photo' => 'دکمهٔ حذف عکس',
            'consent_hint' => 'توضیح رضایت و حریم خصوصی',
            'tracking_label' => 'عنوان لینک پیگیری',
            'tracking_hint' => 'راهنمای نگهداری لینک پیگیری',
            'copy_link' => 'دکمهٔ کپی لینک',
            'copied' => 'پیام کپی‌شدن لینک',
            'download_label' => 'دکمهٔ دریافت تصویر',
            'new_request' => 'دکمهٔ شروع دوباره',
            'waiting_label' => 'پیام انتظار پردازش',
            'empty_products' => 'پیام نبود محصول فعال',
            'privacy_notice' => 'توضیح نگهداری تصاویر؛ متغیر {hours}',
            'login_notice' => 'پیام نیاز به ورود',
            'login_label' => 'دکمهٔ ورود به حساب',
            'demo_notice' => 'پیام حالت آزمایشی',
            'result_label' => 'عنوان تصویر خروجی',
            'selected_label' => 'متن محصول انتخاب‌شده',
            'product_count' => 'عنوان شمارندهٔ محصولات',
            'footer_note' => 'یادداشت پایین صفحه',
            'upload_limit' => 'راهنمای حجم و نوع فایل؛ متغیر {mb}',
            'error_file_type' => 'خطای نوع عکس',
            'error_file_size' => 'خطای حجم عکس',
            'error_file_read' => 'خطای خواندن عکس',
            'error_product' => 'خطای انتخاب‌نشدن محصول',
            'error_photo' => 'خطای انتخاب‌نشدن عکس',
            'error_consent' => 'خطای ندادن رضایت',
            'error_network' => 'خطای ارتباط شبکه',
            'error_unknown' => 'خطای ناشناخته',
            'error_expired' => 'خطای لینک منقضی‌شده',
            'queued_message' => 'پیام ثبت درخواست در صف',
            'processing_message' => 'پیام در حال ساخت تصویر',
            'connection_message' => 'پیام قطع موقت ارتباط',
            'submitting_message' => 'پیام در حال ارسال عکس',
            'retry_status' => 'دکمهٔ بررسی دوبارهٔ وضعیت',
            'preview_label' => 'متن جایگزین عکس ورودی',
            'result_alt' => 'متن جایگزین عکس خروجی',
            'expires_label' => 'عنوان زمان انقضا',
            'failed_label' => 'عنوان نتیجهٔ ناموفق',
            'javascript_notice' => 'پیام نیاز به فعال‌بودن جاوااسکریپت',
            'gallery_hint' => 'راهنمای انتخاب طرح',
            'search_designs' => 'راهنمای جست‌وجوی طرح',
            'search_empty' => 'پیام نبود نتیجهٔ جست‌وجو',
            'selection_placeholder' => 'پیام انتخاب‌نشدن طرح',
            'selection_heading' => 'عنوان خلاصهٔ طرح انتخابی',
            'ready_message' => 'پیام آماده‌بودن فرم',
            'ready_consent' => 'راهنمای تأیید رضایت',
            'upload_tip' => 'نکتهٔ کوتاه عکس مناسب',
            'progress_hint' => 'راهنمای انتظار نتیجه',
            'delayed_message' => 'پیام طولانی‌شدن پردازش',
            'result_loading' => 'پیام دریافت فایل ساخته‌شده',
            'result_load_error' => 'خطای دریافت فایل نتیجه',
            'progress_paused' => 'پیام توقف پیگیری خودکار',
        );
    }
}
