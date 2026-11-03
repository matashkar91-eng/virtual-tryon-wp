<?php
/** Storefront shortcode and its local assets. */
namespace VTO;

defined('ABSPATH') || exit;

final class Frontend {
    public static function init() {
        add_shortcode('virtual_tryon', array(__CLASS__, 'render'));
    }

    public static function labels() {
        return array(
            'eyebrow' => 'اتاق پرو مجازی',
            'step_product' => 'انتخاب محصول',
            'step_upload' => 'عکس شما',
            'step_result' => 'نتیجهٔ پرو',
            'photo_hint' => 'یک عکس واضح با نور مناسب و پس‌زمینهٔ ساده انتخاب کنید. چهره و محل قرار گرفتن محصول در کادر باشند.',
            'choose_photo' => 'انتخاب عکس',
            'replace_photo' => 'تغییر عکس',
            'remove_photo' => 'حذف عکس',
            'consent_hint' => 'فقط عکسی را بفرستید که اجازهٔ استفاده از آن را دارید.',
            'tracking_label' => 'لینک خصوصی پیگیری شما',
            'tracking_hint' => 'این لینک را نگه دارید؛ حتی اگر صفحه بسته شود می‌توانید نتیجه را از همین‌جا دریافت کنید. آن را با دیگران به اشتراک نگذارید.',
            'copy_link' => 'کپی لینک',
            'copied' => 'لینک کپی شد',
            'download_label' => 'دریافت تصویر',
            'new_request' => 'یک پروی دیگر',
            'waiting_label' => 'تصویر شما در حال آماده‌شدن است',
            'empty_products' => 'به‌زودی محصولات قابل پرو در این بخش قرار می‌گیرند.',
            'privacy_notice' => 'لینک عکس و نتیجه تا {hours} ساعت در دسترس است. پس از انقضا، فایل‌های خصوصی در اجرای بعدی پاک‌سازی خودکار حذف می‌شوند.',
            'login_notice' => 'برای استفاده از اتاق پرو، ابتدا وارد حساب کاربری خود شوید.',
            'login_label' => 'ورود به حساب',
            'demo_notice' => 'حالت آزمایشی فعال است؛ این خروجی فقط جریان کار را نمایش می‌دهد و پروی واقعی با هوش مصنوعی نیست.',
            'result_label' => 'این شما، این انتخاب تازه‌تان',
            'selected_label' => 'انتخاب‌شده',
            'product_count' => 'محصول قابل پرو',
            'footer_note' => 'پرو مجازی، تصویری تقریبی می‌سازد و تضمین اندازه یا تن‌خور محصول نیست.',
            'upload_limit' => 'JPG، PNG یا WebP · حداکثر {mb} مگابایت',
            'error_file_type' => 'لطفاً یک تصویر JPG، PNG یا WebP انتخاب کنید.',
            'error_file_size' => 'حجم عکس بیشتر از حد مجاز است. یک تصویر کوچک‌تر انتخاب کنید.',
            'error_file_read' => 'خواندن این عکس ممکن نیست. تصویر دیگری انتخاب کنید.',
            'error_product' => 'ابتدا یک محصول انتخاب کنید.',
            'error_photo' => 'ابتدا عکس خود را انتخاب کنید.',
            'error_consent' => 'برای ارسال عکس، موافقت با پردازش آن لازم است.',
            'error_network' => 'ارتباط برقرار نشد. اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.',
            'error_unknown' => 'این درخواست انجام نشد. دوباره تلاش کنید یا با پشتیبانی فروشگاه تماس بگیرید.',
            'error_expired' => 'این لینک منقضی شده یا درخواست در دسترس نیست. می‌توانید یک پروی تازه بسازید.',
            'queued_message' => 'درخواست ثبت شد و در صف پردازش قرار دارد.',
            'processing_message' => 'در حال ساخت تصویر هستیم. این مرحله ممکن است چند دقیقه طول بکشد.',
            'connection_message' => 'ارتباط موقتاً قطع شده است. لینک پیگیری محفوظ است و دوباره وضعیت را بررسی می‌کنیم.',
            'submitting_message' => 'در حال ارسال امن عکس…',
            'retry_status' => 'بررسی دوبارهٔ وضعیت',
            'preview_label' => 'پیش‌نمایش عکس شما',
            'result_alt' => 'نتیجهٔ پرو مجازی شما',
            'expires_label' => 'زمان پایان دسترسی:',
            'failed_label' => 'تصویر آماده نشد',
            'javascript_notice' => 'برای استفاده از اتاق پرو، جاوااسکریپت مرورگر را فعال کنید.',
        );
    }

    public static function render() {
        $settings = Settings::get();
        $labels = array_merge(self::labels(), isset($settings['labels']) && is_array($settings['labels']) ? $settings['labels'] : array());
        $products = Catalog::all();
        $id = wp_unique_id('vto-');
        $hours = (int) $settings['retention_hours'];
        $labels['privacy_notice'] = str_replace('{hours}', (string) $hours, $labels['privacy_notice']);
        $labels['upload_limit'] = str_replace('{mb}', (string) $settings['max_upload_mb'], $labels['upload_limit']);
        wp_enqueue_style('vto-storefront', VTO_URL . 'assets/frontend.css', array(), VTO_VERSION);
        wp_enqueue_script('vto-storefront', VTO_URL . 'assets/frontend.js', array(), VTO_VERSION, true);
        $config = array(
            'restUrl' => esc_url_raw(rest_url('vto/v1/')),
            'ajaxUrl' => esc_url_raw(add_query_arg('action', 'vto_session', admin_url('admin-ajax.php'))),
            'nonce' => wp_create_nonce('wp_rest'),
            'maxUploadMb' => (int) $settings['max_upload_mb'],
            'mode' => $settings['mode'],
            'labels' => $labels,
            'successMessage' => $settings['success_message'],
            'buttonLabel' => $settings['button_label'],
        );
        wp_add_inline_script('vto-storefront', 'window.VTO_CONFIG = ' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
        $colors = array();
        foreach (array('accent_color' => '--vto-accent', 'bg_color' => '--vto-bg', 'text_color' => '--vto-ink') as $key => $variable) {
            $color = sanitize_hex_color($settings[$key]);
            if ($color) {
                $colors[] = $variable . ':' . $color;
            }
        }
        $logo = $settings['logo_id'] ? wp_get_attachment_image_url((int) $settings['logo_id'], 'medium') : '';
        $banner = $settings['banner_id'] ? wp_get_attachment_image_url((int) $settings['banner_id'], 'large') : '';
        ob_start();
        ?>
        <section id="<?php echo esc_attr($id); ?>" class="vto alignwide" dir="rtl" style="<?php echo esc_attr(implode(';', $colors)); ?>" data-vto-app aria-labelledby="<?php echo esc_attr($id); ?>-title">
            <header class="vto-hero<?php echo $banner ? ' vto-hero--with-image' : ''; ?>">
                <?php if ($banner) : ?><img class="vto-hero-image" src="<?php echo esc_url($banner); ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
                <div class="vto-hero-content">
                    <div class="vto-eyebrow"><?php if ($logo) : ?><img class="vto-logo" src="<?php echo esc_url($logo); ?>" alt="" referrerpolicy="no-referrer"><?php else : ?><span class="vto-spark" aria-hidden="true">✦</span><?php endif; ?><span><?php echo esc_html($labels['eyebrow']); ?></span></div>
                    <h2 id="<?php echo esc_attr($id); ?>-title"><?php echo esc_html($settings['title']); ?></h2>
                    <p><?php echo esc_html($settings['subtitle']); ?></p>
                </div>
                <?php if (!$banner) : ?><div class="vto-hero-art" aria-hidden="true"><span class="vto-art-orbit"></span><span class="vto-art-mirror"><span>✧</span></span><span class="vto-art-dot"></span></div><?php endif; ?>
            </header>
            <?php if ($settings['mode'] === 'demo') : ?><div class="vto-notice vto-demo"><span aria-hidden="true">◈</span><span><?php echo esc_html($labels['demo_notice']); ?></span></div><?php endif; ?>
            <?php if (!empty($settings['require_login']) && !is_user_logged_in()) : ?>
                <div class="vto-empty"><p><?php echo esc_html($labels['login_notice']); ?></p><a class="vto-button" href="<?php echo esc_url(wp_login_url(get_permalink())); ?>"><?php echo esc_html($labels['login_label']); ?></a></div>
            <?php else : ?>
                <ol class="vto-steps" aria-label="<?php echo esc_attr($labels['eyebrow']); ?>">
                    <li class="is-current" data-vto-step="1"><span>۰۱</span><?php echo esc_html($labels['step_product']); ?></li>
                    <li data-vto-step="2"><span>۰۲</span><?php echo esc_html($labels['step_upload']); ?></li>
                    <li data-vto-step="3"><span>۰۳</span><?php echo esc_html($labels['step_result']); ?></li>
                </ol>
                <form class="vto-form" data-vto-form novalidate>
                    <div class="vto-workspace">
                        <fieldset class="vto-products" data-vto-products>
                            <legend class="vto-section-title"><span><?php echo esc_html($settings['product_label']); ?></span><small><?php echo esc_html(number_format_i18n(count($products)) . ' ' . $labels['product_count']); ?></small></legend>
                            <?php if (!$products) : ?><div class="vto-empty"><?php echo esc_html($labels['empty_products']); ?></div><?php endif; ?>
                            <div class="vto-product-grid">
                            <?php foreach ($products as $product) : ?>
                                <label class="vto-product">
                                    <input type="radio" name="product_id" value="<?php echo esc_attr($product['id']); ?>" required>
                                    <span class="vto-product-visual"><?php if (!empty($product['image'])) : ?><img src="<?php echo esc_url($product['image']); ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else : ?><span class="vto-product-placeholder" aria-hidden="true">◇</span><?php endif; ?><span class="vto-product-check" aria-hidden="true">✓</span></span>
                                    <span class="vto-product-name"><?php echo esc_html($product['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                            </div>
                        </fieldset>
                        <div class="vto-photo-panel">
                            <label class="vto-section-title" for="<?php echo esc_attr($id); ?>-photo"><?php echo esc_html($settings['upload_label']); ?></label>
                            <div class="vto-upload" data-vto-dropzone>
                                <div class="vto-upload-placeholder" data-vto-placeholder><span class="vto-upload-icon" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none"><rect x="8" y="8" width="32" height="32" rx="10" stroke="currentColor" stroke-width="1.5"/><circle cx="24" cy="20" r="5" stroke="currentColor" stroke-width="1.5"/><path d="M14 36c1-7 6-10 10-10s9 3 10 10M36 6v8M32 10h8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span><strong><?php echo esc_html($labels['choose_photo']); ?></strong><small><?php echo esc_html($labels['upload_limit']); ?></small></div>
                                <img class="vto-photo-preview" data-vto-preview alt="<?php echo esc_attr($labels['preview_label']); ?>" hidden>
                                <input id="<?php echo esc_attr($id); ?>-photo" class="vto-file-input" name="image" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="<?php echo esc_attr($id); ?>-hint" required>
                                <label class="vto-upload-hitarea" for="<?php echo esc_attr($id); ?>-photo"><span class="vto-sr-only"><?php echo esc_html($labels['choose_photo']); ?></span></label>
                            </div>
                            <div class="vto-photo-actions" data-vto-photo-actions hidden><label for="<?php echo esc_attr($id); ?>-photo" class="vto-text-button"><?php echo esc_html($labels['replace_photo']); ?></label><button type="button" class="vto-text-button" data-vto-remove><?php echo esc_html($labels['remove_photo']); ?></button></div>
                            <p id="<?php echo esc_attr($id); ?>-hint" class="vto-hint"><?php echo esc_html($labels['photo_hint']); ?></p>
                            <div class="vto-consent"><label><input type="checkbox" name="consent" value="1" required><span><?php echo esc_html($settings['consent_text']); ?></span></label><small><?php echo esc_html($labels['consent_hint']); ?></small></div>
                            <button type="submit" class="vto-button vto-submit" data-vto-submit <?php disabled(empty($products)); ?>><span><?php echo esc_html($settings['button_label']); ?></span><span aria-hidden="true">←</span></button>
                            <p class="vto-privacy"><?php echo esc_html($labels['privacy_notice']); ?></p>
                        </div>
                    </div>
                </form>
                <div class="vto-feedback" data-vto-feedback role="status" aria-live="polite" tabindex="-1" hidden></div>
                <section class="vto-job" data-vto-job hidden aria-labelledby="<?php echo esc_attr($id); ?>-job-title">
                    <div class="vto-job-body">
                        <div class="vto-progress-visual" data-vto-spinner aria-hidden="true"><span class="vto-progress-ring"></span><span>✦</span></div>
                        <h3 id="<?php echo esc_attr($id); ?>-job-title" data-vto-job-title><?php echo esc_html($labels['waiting_label']); ?></h3>
                        <p data-vto-job-message aria-live="polite"></p>
                        <img class="vto-result-image" data-vto-result alt="<?php echo esc_attr($labels['result_alt']); ?>" referrerpolicy="no-referrer" hidden>
                        <p class="vto-result-note" data-vto-result-note hidden><?php echo esc_html($labels['footer_note']); ?></p>
                        <p class="vto-expiry" data-vto-expiry hidden></p>
                        <div class="vto-result-actions"><a class="vto-button" data-vto-download hidden download referrerpolicy="no-referrer"><?php echo esc_html($labels['download_label']); ?></a><button class="vto-button vto-button--outline" type="button" data-vto-check hidden><?php echo esc_html($labels['retry_status']); ?></button><button class="vto-text-button" type="button" data-vto-new><?php echo esc_html($labels['new_request']); ?></button></div>
                    </div>
                    <aside class="vto-tracking"><label for="<?php echo esc_attr($id); ?>-tracking"><?php echo esc_html($labels['tracking_label']); ?></label><p><?php echo esc_html($labels['tracking_hint']); ?></p><div class="vto-tracking-control"><input id="<?php echo esc_attr($id); ?>-tracking" type="text" readonly dir="ltr" data-vto-tracking><button type="button" data-vto-copy><?php echo esc_html($labels['copy_link']); ?></button></div></aside>
                </section>
                <noscript><p class="vto-notice"><?php echo esc_html($labels['javascript_notice']); ?></p></noscript>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }
}
