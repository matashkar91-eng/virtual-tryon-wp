<?php
namespace VTO;

defined('ABSPATH') || exit;

/** The merchant-facing configuration and request history. */
final class Admin {
    public static function init() {
        add_action('admin_init', array('VTO\\Settings', 'register'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_vto_job_action', array(__CLASS__, 'job_action'));
        add_action('admin_post_vto_check_connection', array(__CLASS__, 'check_connection'));
    }

    public static function menu() {
        add_menu_page('پرو مجازی', 'پرو مجازی', 'manage_options', 'vto-settings', array(__CLASS__, 'settings_page'), 'dashicons-format-image', 57);
        add_submenu_page('vto-settings', 'تنظیمات پرو مجازی', 'تنظیمات', 'manage_options', 'vto-settings', array(__CLASS__, 'settings_page'));
        add_submenu_page('vto-settings', 'درخواست‌های پرو مجازی', 'درخواست‌ها و تصاویر', 'manage_options', 'vto-jobs', array(__CLASS__, 'jobs_page'));
    }

    public static function assets($hook) {
        if (strpos($hook, 'vto-') === false) {
            return;
        }
        wp_enqueue_style('vto-admin', plugins_url('../assets/admin.css', __FILE__), array(), VTO_VERSION);
        if (strpos($hook, 'vto-settings') !== false) {
            wp_enqueue_media();
        }
    }

    private static function authorize() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'virtual-tryon-wp'));
        }
    }

    private static function input($settings, $key, $type = 'text', $attrs = '') {
        printf('<input class="regular-text" type="%s" id="vto-%s" name="vto_settings[%s]" value="%s" %s>', esc_attr($type), esc_attr($key), esc_attr($key), esc_attr($settings[$key]), $attrs); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal static attrs only.
    }

    private static function textarea($settings, $key, $rows = 3) {
        printf('<textarea class="large-text" id="vto-%s" name="vto_settings[%s]" rows="%d">%s</textarea>', esc_attr($key), esc_attr($key), (int) $rows, esc_textarea($settings[$key]));
    }

    private static function select($settings, $key, $options) {
        printf('<select id="vto-%s" name="vto_settings[%s]">', esc_attr($key), esc_attr($key));
        foreach ($options as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($settings[$key], $value, false), esc_html($label));
        }
        echo '</select>';
    }

    private static function media_field($settings, $key) {
        $id = absint($settings[$key]);
        printf('<div class="vto-media"><input type="hidden" name="vto_settings[%s]" value="%d"><span class="vto-media-preview">', esc_attr($key), $id);
        if ($id) {
            echo wp_get_attachment_image($id, 'thumbnail');
        }
        echo '</span><button type="button" class="button vto-pick-image">انتخاب تصویر</button> <button type="button" class="button-link-delete vto-clear-image">حذف</button></div>';
    }

    public static function settings_page() {
        self::authorize();
        $s = Settings::get();
        ?>
        <div class="wrap vto-admin" dir="rtl">
            <div class="vto-admin-header"><div><span class="vto-kicker">VIRTUAL TRY-ON</span><h1>اتاق پروی فروشگاه شما</h1><p>یک افزونه، با ظاهر و محصولات مخصوص فروشگاه شما.</p></div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=vto-jobs')); ?>">درخواست‌ها و تصاویر ←</a></div>
            <?php settings_errors(); ?>
            <?php self::connection_notice(); ?>
            <?php if (class_exists('VTO\\Storage')) : $storage = Storage::directory(); if (is_wp_error($storage)) : ?><div class="notice notice-error"><p><strong>فضای خصوصی تصاویر آماده نیست:</strong> <?php echo esc_html($storage->get_error_message()); ?> مسیر قابل‌نوشتن خارج از ریشهٔ عمومی سایت را با ثابت <code>VTO_PRIVATE_DIR</code> در <code>wp-config.php</code> تعیین کنید. فایل عکس مشتری نباید مستقیم از وب قابل دریافت باشد.</p></div><?php endif; endif; ?>
            <?php if (!extension_loaded('gd') && !extension_loaded('imagick')) : ?><div class="notice notice-warning"><p>برای پردازش امن تصویر، افزونهٔ PHP GD یا Imagick را روی میزبان فعال کنید.</p></div><?php endif; ?>
            <div class="vto-guide"><strong>شروع سریع</strong><span>۱. محصول‌ها را فعال کنید.</span><span>۲. یک برگه بسازید و کد <code>[virtual_tryon]</code> را در آن بگذارید.</span><span>۳. ابتدا در حالت آزمایشی بررسی کنید، سپس API را فعال کنید.</span></div>
            <nav class="vto-nav" aria-label="بخش‌های تنظیمات"><a href="#vto-appearance">ظاهر و متن‌ها</a><a href="#vto-products">محصول‌ها</a><a href="#vto-provider">هوش مصنوعی</a><a href="#vto-privacy">دسترسی و نگهداری</a></nav>
            <form action="options.php" method="post">
                <?php settings_fields('vto_settings_group'); ?>
                <section class="vto-card" id="vto-appearance"><h2>ظاهر و متن‌های صفحه</h2><p class="description">این تنظیمات فقط صفحهٔ پرو را تغییر می‌دهند و به قالب سایت وابسته نیستند.</p>
                    <table class="form-table" role="presentation"><tbody>
                    <?php foreach (array('title' => 'عنوان اصلی', 'subtitle' => 'توضیح زیر عنوان', 'product_label' => 'عنوان انتخاب محصول', 'upload_label' => 'عنوان بارگذاری عکس', 'button_label' => 'متن دکمهٔ ساخت', 'success_message' => 'پیام آماده‌شدن نتیجه', 'consent_text' => 'متن رضایت ارسال عکس') as $key => $label) : ?>
                        <tr><th scope="row"><label for="vto-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><?php if (in_array($key, array('subtitle', 'consent_text'), true)) { self::textarea($s, $key); } else { self::input($s, $key); } ?></td></tr>
                    <?php endforeach; ?>
                    <?php foreach (array('accent_color' => 'رنگ اصلی و دکمه‌ها', 'bg_color' => 'رنگ پس‌زمینه', 'text_color' => 'رنگ نوشته‌ها') as $key => $label) : ?>
                        <tr><th scope="row"><label for="vto-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><?php self::input($s, $key, 'color'); ?></td></tr>
                    <?php endforeach; ?>
                        <tr><th scope="row">بنر صفحه</th><td><?php self::media_field($s, 'banner_id'); ?></td></tr>
                        <tr><th scope="row">نشان فروشگاه</th><td><?php self::media_field($s, 'logo_id'); ?></td></tr>
                    </tbody></table>
                    <details><summary>متن‌های تکمیلی و راهنماها</summary><p class="description">خالی بگذارید تا متن پیش‌فرض فارسی نمایش داده شود. متن‌ها به صورت ساده و بدون HTML هستند.</p><table class="form-table" role="presentation"><tbody>
                        <?php foreach (Settings::label_names() as $key => $label) : ?>
                            <tr><th scope="row"><label for="vto-label-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><textarea class="large-text" rows="2" id="vto-label-<?php echo esc_attr($key); ?>" name="vto_settings[labels][<?php echo esc_attr($key); ?>]" placeholder="<?php $label_defaults = Frontend::labels(); echo esc_attr(isset($label_defaults[$key]) ? $label_defaults[$key] : ''); ?>"><?php echo esc_textarea(isset($s['labels'][$key]) ? $s['labels'][$key] : ''); ?></textarea></td></tr>
                        <?php endforeach; ?>
                    </tbody></table></details>
                </section>

                <section class="vto-card" id="vto-products"><h2>محصول‌های قابل پرو</h2><p>فقط محصول‌هایی که اینجا انتخاب می‌کنید برای مشتری نمایش داده می‌شوند. از تصویر روشن و بدون مانع برای هر محصول استفاده کنید.</p>
                    <table class="form-table" role="presentation"><tbody>
                        <tr><th scope="row"><label for="vto-garment_type">گروه فروشگاه</label></th><td><?php self::select($s, 'garment_type', array('scarf' => 'روسری و شال', 'clothing' => 'پوشاک', 'glasses' => 'عینک', 'jewelry' => 'زیورآلات')); ?><p class="description">انتخاب گروه جایگزین آزمایش کیفیت مدل برای محصولات شما نیست.</p></td></tr>
                        <tr><th scope="row"><label for="vto-product_ids">شناسه‌های محصول ووکامرس</label></th><td><input class="large-text" dir="ltr" id="vto-product_ids" name="vto_settings[product_ids]" value="<?php echo esc_attr(implode(', ', $s['product_ids'])); ?>" placeholder="123, 456, 789"><p class="description">شناسه‌ها را با ویرگول جدا کنید. شناسهٔ هر محصول در فهرست محصولات ووکامرس مشخص است. فهرست خالی یعنی هیچ محصول ووکامرسی فعال نیست.</p>
                        <?php if (!function_exists('wc_get_product')) : ?><p class="vto-warning">ووکامرس فعال نیست؛ می‌توانید فعلاً از فهرست مستقل زیر استفاده کنید.</p><?php else : ?>
                            <p><label for="vto-product-search">جست‌وجو در تمام محصولات ووکامرس</label><br><input class="regular-text" type="search" id="vto-product-search" placeholder="نام، شناسه یا SKU؛ دست‌کم دو نویسه" autocomplete="off"><span id="vto-product-search-status" role="status" aria-live="polite"></span></p><div class="vto-product-helper" id="vto-product-search-results"></div>
                            <details><summary>انتخاب سریع از ۴۰ محصول اخیر</summary><div class="vto-product-helper"><?php
                            foreach (wc_get_products(array('status' => 'publish', 'limit' => 40, 'orderby' => 'date', 'order' => 'DESC')) as $product) {
                                printf('<button type="button" class="button vto-add-product" data-id="%d">%s <code>#%d</code></button> ', absint($product->get_id()), esc_html($product->get_name()), absint($product->get_id()));
                            }
                            ?></div><p class="description">روی محصول بزنید تا شناسه‌اش اضافه شود؛ برای حذف، شناسه را از کادر بالا پاک کنید. این راهنما فقط محصولات اخیر را نشان می‌دهد؛ جست‌وجو و کادر شناسه محدود به این فهرست نیستند.</p></details>
                        <?php endif; ?></td></tr>
                    </tbody></table>
                    <details><summary>فهرست مستقل محصولات؛ بدون وابستگی به ووکامرس</summary><p class="description">برای هر محصول نام و تصویر انتخاب کنید. ردیف بدون نام یا تصویر ذخیره نمی‌شود. شناسهٔ ثابت را پس از انتشار تغییر ندهید.</p>
                    <table class="widefat vto-manual-table"><thead><tr><th>شناسهٔ ثابت</th><th>نام محصول</th><th>تصویر</th><th>حذف</th></tr></thead><tbody id="vto-manual-rows">
                        <?php foreach ($s['manual_products'] as $index => $product) { self::manual_row($index, $product); } ?>
                    </tbody></table><p><button type="button" class="button" id="vto-add-manual">+ افزودن محصول مستقل</button></p></details>
                </section>

                <section class="vto-card" id="vto-provider"><h2>اتصال به اول‌ای‌آی (AvalAI)</h2><p class="vto-warning">اتصال واقعی این نسخه هنوز با حساب AvalAI تأیید نشده است. آداپتور با قالب ویرایش تصویر سازگار با OpenAI و دو تصویر ورودی نوشته شده؛ پیش از عرضه، پشتیبانی مسیر و مدل را با مستندات و حساب خود بررسی و یک درخواست واقعی آزمایش کنید.</p><p><a href="#vto-connection-test">بررسی اتصال و دسترسی مدل بدون ارسال عکس ↓</a></p><table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row"><label for="vto-mode">حالت اجرا</label></th><td><?php self::select($s, 'mode', array('demo' => 'آزمایشی؛ بدون فراخوانی API و بدون تعویض لباس', 'avalai' => 'واقعی؛ اتصال به AvalAI و مصرف اعتبار')); ?><p class="description">حالت آزمایشی فقط بارگذاری، صف، پیگیری و دریافت فایل را بررسی می‌کند؛ تصویر پرو واقعی تولید نمی‌کند.</p></td></tr>
                    <tr><th scope="row"><label for="vto-api_key">کلید API</label></th><td><input class="regular-text" type="password" id="vto-api_key" name="vto_settings[api_key]" value="" autocomplete="new-password" dir="ltr" spellcheck="false"><p class="description"><?php echo defined('VTO_AVALAI_KEY') && VTO_AVALAI_KEY ? 'کلید از ثابت VTO_AVALAI_KEY در تنظیمات سرور خوانده می‌شود.' : ($s['api_key'] ? 'کلید ذخیره شده است. خالی گذاشتن این کادر کلید قبلی را نگه می‌دارد.' : 'هنوز کلیدی ذخیره نشده است. کلید را فقط از حساب AvalAI دریافت کنید.'); ?> کلید به مرورگر مشتری ارسال نمی‌شود.</p><label><input type="checkbox" name="vto_settings[clear_api_key]" value="1"> حذف کلید ذخیره‌شده در پایگاه داده</label><p class="description">برای مدیریت کلید در سرور می‌توانید ثابت <code>VTO_AVALAI_KEY</code> را در <code>wp-config.php</code> تعریف کنید. کلید ذخیره‌شده در وردپرس رمزگذاری جداگانه ندارد؛ دسترسی مدیر و پایگاه داده را محدود نگه دارید.</p></td></tr>
                    <tr><th scope="row"><label for="vto-model">شناسهٔ مدل ویرایش تصویر</label></th><td><?php self::input($s, 'model', 'text', 'dir="ltr" spellcheck="false"'); ?><p class="description">مدل باید در حساب شما از ویرایش تصویر با دو تصویر ورودی پشتیبانی کند. تغییر این نام به‌تنهایی پشتیبانی از قالب API دیگری اضافه نمی‌کند.</p></td></tr>
                    <tr><th scope="row"><label for="vto-prompt">الگوی پرامپت</label></th><td><?php self::textarea($s, 'prompt', 7); ?><p class="description">متغیرهای قابل جایگزینی: <code>{product}</code> نام محصول و <code>{category}</code> گروه محصول. پرامپت باید حفظ چهره، تناسب بدن، رنگ و طرح محصول را روشن بیان کند. پیش از عرضه برای هر گروه چند عکس واقعی را آزمایش کنید؛ خروجی تضمین اندازه یا تن‌خور دقیق نیست.</p></td></tr>
                </tbody></table></section>

                <section class="vto-card" id="vto-privacy"><h2>دسترسی، محدودیت و نگهداری</h2><table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row">ورود به حساب</th><td><label><input type="checkbox" name="vto_settings[require_login]" value="1" <?php checked($s['require_login']); ?>> فقط کاربران واردشده بتوانند تصویر بسازند</label><p class="description">با خاموش‌بودن این گزینه، مهمان‌ها هم می‌توانند با محدودیت درخواست استفاده کنند.</p></td></tr>
                    <tr><th scope="row"><label for="vto-max_upload_mb">حداکثر حجم عکس (مگابایت)</label></th><td><?php self::input($s, 'max_upload_mb', 'number', 'min="1" max="12"'); ?><p class="description">حد PHP و وب‌سرور هم اعمال می‌شود؛ افزایش این عدد به‌تنهایی محدودیت سرور را تغییر نمی‌دهد.</p></td></tr>
                    <tr><th scope="row"><label for="vto-rate_limit">حد درخواست هر کاربر در ساعت</label></th><td><?php self::input($s, 'rate_limit', 'number', 'min="1" max="50"'); ?><p class="description">برای مهمان‌ها محدودیت بر اساس نشانی شبکه اعمال می‌شود؛ کاربران یک شبکه ممکن است این سهمیه را مشترک داشته باشند.</p></td></tr>
                    <tr><th scope="row"><label for="vto-global_rate_limit">سقف کل درخواست‌های فروشگاه در ساعت</label></th><td><?php self::input($s, 'global_rate_limit', 'number', 'min="1" max="1000"'); ?><p class="description">این سقف به کنترل مصرف اعتبار کمک می‌کند. جایگزین سقف هزینه در حساب سرویس‌دهنده نیست.</p></td></tr>
                    <tr><th scope="row"><label for="vto-retention_hours">مدت نگهداری عکس و پیگیری (ساعت)</label></th><td><?php self::input($s, 'retention_hours', 'number', 'min="1" max="168"'); ?><p class="description">پاک‌سازی با اجرای زمان‌بند وردپرس انجام می‌شود. برای حذف به‌موقع، روی میزبان واقعی cron منظم تنظیم کنید. حذف محلی شامل سیاست نگهداری سرویس هوش مصنوعی نمی‌شود.</p></td></tr>
                </tbody></table><p class="vto-warning">عکس مشتری و لینک خصوصی پیگیری دادهٔ شخصی هستند. لینک را فقط با صاحب عکس به اشتراک بگذارید و نحوهٔ پردازش توسط سرویس بیرونی را در سیاست حریم خصوصی فروشگاه توضیح دهید.</p></section>
                <?php submit_button('ذخیرهٔ تنظیمات فروشگاه'); ?>
            </form>
            <section class="vto-card" id="vto-connection-test"><h2>بررسی اتصال و دسترسی مدل</h2><p>ابتدا کلید و نام مدل را در تنظیمات بالا <strong>ذخیره کنید</strong>. این بررسی از تنظیمات ذخیره‌شده استفاده می‌کند و فقط فهرست مدل‌های قابل دسترس حساب را می‌خواند؛ عکس ارسال نمی‌شود و تصویر تازه‌ای ساخته نمی‌شود.</p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="vto_check_connection">
                    <?php wp_nonce_field('vto_check_connection'); ?>
                    <?php submit_button('بررسی اتصال و دسترسی مدل (بدون ارسال عکس)', 'secondary', 'submit', false); ?>
                </form>
                <p class="description">موفقیت این بررسی فقط ارتباط، احراز هویت و دسترسی به فهرست مدل‌ها را نشان می‌دهد؛ پشتیبانی از ویرایش دو عکس، اعتبار کافی و کیفیت پرو را تأیید نمی‌کند. برای تکرار بررسی دست‌کم ۱۵ ثانیه صبر کنید.</p>
                <p class="description">پس از ذخیرهٔ حالت واقعی، اگر صفحهٔ پرو هنوز برچسب آزمایشی دارد، کش <strong>همان برگهٔ پرو</strong> را در افزونه‌هایی مانند WP Rocket پاک و صفحه را تازه کنید. لینک یک درخواست قدیمی همچنان نتیجه و تنظیمات زمان ثبت خودش را نشان می‌دهد.</p>
            </section>
            <script type="text/template" id="vto-manual-template"><?php self::manual_row('__INDEX__', array('id' => '', 'name' => '', 'image_id' => 0, 'category' => 'auto')); ?></script>
        </div>
        <script>
        (function(){
            'use strict';
            var next = <?php echo count($s['manual_products']); ?>;
            var productSearch = document.getElementById('vto-product-search');
            if (productSearch) {
                var searchTimer;
                var searchSequence = 0;
                var searchStatus = document.getElementById('vto-product-search-status');
                var searchResults = document.getElementById('vto-product-search-results');
                productSearch.addEventListener('input', function(){
                    window.clearTimeout(searchTimer);
                    var term = productSearch.value.trim();
                    var sequence = ++searchSequence;
                    searchResults.textContent = '';
                    searchStatus.textContent = '';
                    if (term.length < 2) { return; }
                    searchTimer = window.setTimeout(function(){
                        searchStatus.textContent = ' در حال جست‌وجو…';
                        var params = new URLSearchParams({action:'woocommerce_json_search_products_and_variations',security:<?php echo wp_json_encode(wp_create_nonce('search-products')); ?>,term:term});
                        fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?> + '?' + params.toString(), {credentials:'same-origin'})
                            .then(function(response){if(!response.ok){throw new Error('search_failed');}return response.json();})
                            .then(function(products){
                                if(sequence !== searchSequence){return;}
                                if(!products || typeof products !== 'object' || Array.isArray(products)){throw new Error('search_failed');}
                                searchResults.textContent = '';
                                Object.keys(products).forEach(function(id){
                                    if(!/^\d+$/.test(id)){return;}
                                    var button = document.createElement('button');
                                    button.type = 'button';button.className = 'button vto-add-product';button.dataset.id = id;
                                    button.textContent = products[id];searchResults.appendChild(button);
                                });
                                searchStatus.textContent = searchResults.children.length ? ' برای انتخاب روی محصول بزنید.' : ' محصولی پیدا نشد.';
                            }).catch(function(){if(sequence === searchSequence){searchStatus.textContent = ' جست‌وجو در دسترس نیست؛ شناسه را مستقیم وارد کنید.';}});
                    }, 350);
                });
            }
            document.addEventListener('click', function(event){
                var pick = event.target.closest('.vto-pick-image');
                if (pick) {
                    event.preventDefault();
                    var field = pick.closest('.vto-media');
                    var frame = wp.media({title:'انتخاب تصویر',button:{text:'استفاده از این تصویر'},library:{type:'image'},multiple:false});
                    frame.on('select',function(){
                        var attachment = frame.state().get('selection').first().toJSON();
                        field.querySelector('input[type=hidden]').value = attachment.id;
                        var preview = field.querySelector('.vto-media-preview');
                        preview.textContent = '';
                        var img = document.createElement('img');
                        img.src = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
                        img.alt = '';
                        preview.appendChild(img);
                    });
                    frame.open();
                }
                var clear = event.target.closest('.vto-clear-image');
                if (clear) {
                    var media = clear.closest('.vto-media');
                    media.querySelector('input[type=hidden]').value = 0;
                    media.querySelector('.vto-media-preview').textContent = '';
                }
                var add = event.target.closest('#vto-add-manual');
                if (add) {
                    document.getElementById('vto-manual-rows').insertAdjacentHTML('beforeend',document.getElementById('vto-manual-template').innerHTML.replace(/__INDEX__/g,String(next++)));
                }
                var remove = event.target.closest('.vto-remove-row');
                if (remove) { remove.closest('tr').remove(); }
                var product = event.target.closest('.vto-add-product');
                if (product) {
                    var input = document.getElementById('vto-product_ids');
                    var values = input.value.split(/[\s,،]+/).filter(Boolean);
                    if (values.indexOf(product.dataset.id) === -1) { values.push(product.dataset.id); }
                    input.value = values.join(', ');
                }
            });
        }());
        </script>
        <?php
    }

    private static function manual_row($index, $product) {
        $prefix = 'vto_settings[manual_products][' . $index . ']';
        ?>
        <tr><td><input type="text" dir="ltr" name="<?php echo esc_attr($prefix); ?>[id]" value="<?php echo esc_attr($product['id']); ?>" placeholder="scarf-01" aria-label="شناسهٔ ثابت"></td><td><input type="text" name="<?php echo esc_attr($prefix); ?>[name]" value="<?php echo esc_attr($product['name']); ?>" aria-label="نام محصول"><input type="hidden" name="<?php echo esc_attr($prefix); ?>[category]" value="<?php echo esc_attr(isset($product['category']) ? $product['category'] : 'auto'); ?>"></td><td><div class="vto-media"><input type="hidden" name="<?php echo esc_attr($prefix); ?>[image_id]" value="<?php echo absint($product['image_id']); ?>"><span class="vto-media-preview"><?php if ($product['image_id']) { echo wp_get_attachment_image($product['image_id'], 'thumbnail'); } ?></span><button type="button" class="button vto-pick-image">انتخاب تصویر</button></div></td><td><button type="button" class="button-link-delete vto-remove-row">حذف ردیف</button></td></tr>
        <?php
    }

    /** Read-only account probe, never image generation. Results are per-admin and short-lived. */
    public static function check_connection() {
        self::authorize();
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die('این عملیات باید با فرم بررسی اتصال انجام شود.', '', array('response' => 405));
        }
        check_admin_referer('vto_check_connection');
        $user_id = get_current_user_id();
        $guard = 'vto_connection_guard_' . $user_id;
        if (get_transient($guard)) {
            set_transient('vto_connection_throttled_' . $user_id, true, 15);
            wp_safe_redirect(admin_url('admin.php?page=vto-settings#vto-connection-test'));
            exit;
        }
        set_transient($guard, true, 15);
        delete_transient('vto_connection_throttled_' . $user_id);
        try {
            $result = Provider::check_connection();
        } catch (\Throwable $exception) {
            // Exception messages may contain credentials; never store or render them.
            $result = new \WP_Error('vto_probe_exception', 'بررسی اتصال کامل نشد. پیکربندی PHP و ارتباط سرور را بررسی کنید.');
        }
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $notice = array(
                'type' => 'error',
                'code' => substr(sanitize_key($result->get_error_code()), 0, 100),
                'message' => substr(sanitize_text_field($result->get_error_message()), 0, 1000),
                'detail' => is_array($data) && isset($data['detail']) && is_scalar($data['detail']) ? substr(sanitize_text_field($data['detail']), 0, 1000) : '',
                'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            );
        } elseif (is_array($result) && isset($result['model'], $result['model_count'], $result['model_found'])) {
            $notice = array(
                'type' => 'success',
                'model' => substr(sanitize_text_field($result['model']), 0, 190),
                'model_found' => (bool) $result['model_found'],
                'model_count' => min(1000000, absint($result['model_count'])),
                'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            );
        } else {
            $notice = array('type' => 'error', 'code' => 'vto_probe_invalid_result', 'message' => 'پاسخ بررسی اتصال قابل استفاده نیست؛ تنظیمات سرویس را بررسی کنید.', 'detail' => '', 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'));
        }
        set_transient('vto_connection_result_' . $user_id, $notice, 120);
        wp_safe_redirect(admin_url('admin.php?page=vto-settings#vto-connection-test'));
        exit;
    }

    private static function connection_notice() {
        $user_id = get_current_user_id();
        if (get_transient('vto_connection_throttled_' . $user_id)) {
            echo '<div class="notice notice-warning"><p>برای بررسی دوبارهٔ اتصال، ۱۵ ثانیه فاصله بدهید. نتیجهٔ زیر مربوط به آخرین بررسی انجام‌شده است.</p></div>';
        }
        $notice = get_transient('vto_connection_result_' . $user_id);
        if (!is_array($notice) || empty($notice['type'])) {
            return;
        }
        if ($notice['type'] === 'success') {
            ?>
            <div class="notice <?php echo !empty($notice['model_found']) ? 'notice-success' : 'notice-warning'; ?>"><p><strong>ارتباط و دریافت فهرست مدل‌ها موفق بود.</strong> مدل ذخیره‌شده: <code dir="ltr"><?php echo esc_html(isset($notice['model']) ? $notice['model'] : ''); ?></code>؛ <?php echo !empty($notice['model_found']) ? 'این مدل در فهرست حساب پیدا شد.' : 'این مدل در فهرست حساب پیدا نشد؛ دسترسی و نام دقیق آن را بررسی کنید.'; ?> تعداد مدل‌های گزارش‌شده: <?php echo absint(isset($notice['model_count']) ? $notice['model_count'] : 0); ?>.</p><p>این نتیجه فقط ارتباط، احراز هویت و فهرست مدل‌ها را بررسی می‌کند؛ ویرایش تصویر و ساخت پرو آزمایش نشده است.</p><p class="description">زمان بررسی: <span dir="ltr"><?php echo esc_html(isset($notice['checked_at']) ? $notice['checked_at'] : ''); ?></span> (UTC)</p></div>
            <?php
        } else {
            ?>
            <div class="notice notice-error"><p><strong>بررسی اتصال موفق نشد.</strong> <?php echo esc_html(isset($notice['message']) ? $notice['message'] : ''); ?></p><?php if (!empty($notice['code'])) : ?><p><code dir="ltr"><?php echo esc_html($notice['code']); ?></code></p><?php endif; ?><?php if (!empty($notice['detail'])) : ?><details><summary>جزئیات فنی برای مدیر</summary><pre class="vto-diagnostic" dir="auto"><?php echo esc_html($notice['detail']); ?></pre></details><?php endif; ?><p class="description">زمان بررسی: <span dir="ltr"><?php echo esc_html(isset($notice['checked_at']) ? $notice['checked_at'] : ''); ?></span> (UTC). هیچ عکس یا درخواست ساخت تصویری در این بررسی ارسال نشد.</p></div>
            <?php
        }
    }

    public static function job_action() {
        self::authorize();
        $id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        $action = isset($_POST['job_action']) ? sanitize_key(wp_unslash($_POST['job_action'])) : '';
        check_admin_referer('vto_job_' . $action . '_' . $id);
        $result = new \WP_Error('invalid_action', 'عملیات نامعتبر است.');
        if ($action === 'retry') {
            $result = Jobs::retry($id);
        } elseif ($action === 'recover') {
            $result = Jobs::recover($id);
        } elseif ($action === 'delete') {
            $result = Jobs::delete($id);
        }
        $url = add_query_arg(array('page' => 'vto-jobs', 'vto_notice' => is_wp_error($result) ? 'error' : $action), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private static function job_button($id, $action, $label, $confirm_paid_retry = false) {
        ?><form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="vto-job-action"><input type="hidden" name="action" value="vto_job_action"><input type="hidden" name="job_id" value="<?php echo absint($id); ?>"><input type="hidden" name="job_action" value="<?php echo esc_attr($action); ?>"><?php wp_nonce_field('vto_job_' . $action . '_' . $id); ?><button class="button button-small<?php echo $action === 'delete' ? ' vto-delete-job' : ($confirm_paid_retry ? ' vto-retry-job' : ''); ?>" type="submit"><?php echo esc_html($label); ?></button></form><?php
    }

    /** A failed live request may have reached the provider before its result was lost. */
    private static function uncertain_billing_outcome(array $job) {
        $code = isset($job['error_code']) ? sanitize_key($job['error_code']) : '';
        $uncertain_codes = array(
            'vto_provider_timeout', 'vto_provider_transport', 'vto_provider_dns',
            'vto_provider_connect', 'vto_provider_tls', 'vto_provider_blocked',
            'vto_provider_response_size', 'vto_provider_format', 'vto_provider_pending',
            'vto_provider_image', 'vto_provider_result_url', 'vto_provider_reference',
            'vto_provider_unavailable', 'worker_interrupted', 'worker_error',
        );
        return isset($job['status']) && 'failed' === $job['status']
            && isset($job['mode']) && 'avalai' === $job['mode']
            && empty($job['reference_path'])
            && in_array($code, $uncertain_codes, true);
    }

    public static function jobs_page() {
        self::authorize();
        $jobs = Jobs::list_jobs(50);
        $status_names = array('queued' => 'در صف', 'pending' => 'در انتظار', 'processing' => 'در حال پردازش', 'completed' => 'آماده', 'succeeded' => 'آماده', 'failed' => 'ناموفق', 'expired' => 'منقضی');
        ?>
        <div class="wrap vto-admin" dir="rtl"><div class="vto-admin-header"><div><span class="vto-kicker">REQUEST HISTORY</span><h1>درخواست‌ها و تصاویر</h1><p>۵۰ درخواست اخیر؛ عکس‌ها فقط برای مدیر مجاز قابل مشاهده‌اند.</p></div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=vto-settings')); ?>">تنظیمات فروشگاه ←</a></div>
            <?php if (isset($_GET['vto_notice'])) : $notice = sanitize_key(wp_unslash($_GET['vto_notice'])); ?>
                <div class="notice <?php echo $notice === 'error' ? 'notice-error' : 'notice-success'; ?>"><p><?php echo $notice === 'error' ? 'عملیات انجام نشد. جزئیات همان ردیف را بررسی کنید؛ ممکن است درخواست یا لینک نتیجه منقضی شده باشد.' : ($notice === 'delete' ? 'درخواست و تصاویر محلی آن حذف شدند.' : ($notice === 'recover' ? 'نتیجهٔ قبلی دریافت و ذخیره شد؛ درخواست ساخت تصویر جدیدی ارسال نشد.' : 'درخواست برای ساخت دوباره ثبت شد؛ فراخوانی API ممکن است اعتبار مصرف کند.')); ?></p></div>
            <?php endif; ?>
            <p class="vto-warning">«ساخت دوباره» می‌تواند اعتبار API مصرف کند. «دریافت دوبارهٔ نتیجه» فقط دریافت خروجی قبلی را امتحان می‌کند و درخواست ساخت جدید نمی‌فرستد. حذف، فایل‌های محلی را پاک می‌کند و درخواست ارسال‌شده به سرویس بیرونی را پس نمی‌گیرد.</p>
            <div class="vto-jobs-scroll"><table class="widefat striped vto-jobs-table"><thead><tr><th>شناسه / محصول</th><th>وضعیت / زمان</th><th>عکس‌ها</th><th>جزئیات و خطا</th><th>عملیات</th></tr></thead><tbody>
            <?php if (!$jobs) : ?><tr><td colspan="5">هنوز درخواستی ثبت نشده است. از برگهٔ پرو یک درخواست آزمایشی بسازید.</td></tr><?php endif; ?>
            <?php foreach ($jobs as $job) : $job = (array) $job; $id = absint($job['id']); $status = isset($job['status']) ? $job['status'] : ''; $uncertain_billing = self::uncertain_billing_outcome($job); ?>
                <tr><td><strong>#<?php echo $id; ?></strong><br><?php echo esc_html(isset($job['product_name']) ? $job['product_name'] : ''); ?></td><td><span class="vto-status vto-status-<?php echo esc_attr(sanitize_html_class($status)); ?>"><?php echo esc_html(isset($status_names[$status]) ? $status_names[$status] : $status); ?></span><br><time dir="ltr"><?php echo esc_html(isset($job['created_at']) ? $job['created_at'] : ''); ?> UTC</time><br><small>تعداد تلاش: <?php echo absint(isset($job['attempts']) ? $job['attempts'] : 0); ?></small></td><td>
                <?php foreach (array('input' => 'عکس ورودی', 'output' => 'تصویر خروجی') as $kind => $label) : if (empty($job[$kind . '_path'])) { continue; } $image_url = add_query_arg(array('kind' => $kind, '_wpnonce' => wp_create_nonce('wp_rest')), rest_url('vto/v1/admin/jobs/' . $id . '/image')); ?>
                    <a class="vto-job-image" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($image_url); ?>"><img loading="lazy" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($label); ?>"><span><?php echo esc_html($label); ?></span></a>
                <?php endforeach; ?></td><td>
                    <?php if (!empty($job['error_code'])) : ?><code dir="ltr"><?php echo esc_html($job['error_code']); ?></code><br><?php endif; ?>
                    <?php echo esc_html(isset($job['error_message']) ? $job['error_message'] : ''); ?>
                    <?php if ($status === 'failed' && !empty($job['reference_path'])) : ?><p class="description">سرویس برای این درخواست نشانیِ نتیجه برگردانده و مرجع آن به‌صورت خصوصی ذخیره شده است. اگر لینک هنوز معتبر و منقضی‌نشده باشد، می‌توانید با «دریافت دوبارهٔ نتیجه» آن را بدون ساخت تصویر جدید دریافت کنید.</p><?php endif; ?>
                    <?php if ($uncertain_billing) : ?><p class="description"><strong>هشدار هزینه:</strong> قطع ارتباط یا پاسخ ناقص ثابت نمی‌کند ساخت انجام نشده است. پیش از «ساخت دوباره»، تاریخچه و مصرف همین درخواست را در AvalAI بررسی کنید؛ اصلاح زمان انتظار نتیجهٔ قبلی را بازیابی نمی‌کند.</p><?php endif; ?>
                    <?php if (!empty($job['error_detail'])) : ?><details><summary>جزئیات فنی خطا برای مدیر</summary><pre class="vto-diagnostic" dir="auto"><?php echo esc_html($job['error_detail']); ?></pre></details><?php endif; ?>
                    <?php if (!empty($job['provider_request_id'])) : ?><br><small>شناسهٔ سرویس: <code dir="ltr"><?php echo esc_html($job['provider_request_id']); ?></code></small><?php endif; ?>
                    <details><summary>تنظیمات هنگام ثبت درخواست</summary><dl class="vto-job-snapshot"><dt>حالت اجرا</dt><dd><?php echo isset($job['mode']) && $job['mode'] === 'avalai' ? 'واقعی؛ AvalAI' : 'آزمایشی؛ بدون ساخت تصویر واقعی'; ?></dd><dt>مدل</dt><dd><code dir="ltr"><?php echo esc_html(isset($job['model']) ? $job['model'] : ''); ?></code></dd><dt>پرامپت ارسال‌شده</dt><dd><pre class="vto-diagnostic" dir="auto"><?php echo esc_html(isset($job['prompt']) ? $job['prompt'] : ''); ?></pre></dd></dl><p class="description">تلاش دوباره از همین حالت، مدل و پرامپت ذخیره‌شده استفاده می‌کند. برای آزمایش تنظیمات جدید، یک درخواست تازه بسازید.</p></details>
                    </td><td><?php if ($status === 'failed' && !empty($job['reference_path'])) { self::job_button($id, 'recover', 'دریافت دوبارهٔ نتیجه (بدون ساخت جدید)'); } if ($status === 'failed' && empty($job['reference_path'])) { self::job_button($id, 'retry', 'ساخت دوبارهٔ تصویر (احتمال مصرف اعتبار)', isset($job['mode']) && 'avalai' === $job['mode']); } self::job_button($id, 'delete', 'حذف عکس‌ها و درخواست'); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div><p class="description">برای تازه‌کردن وضعیت، صفحه را بازخوانی کنید. زمان‌ها بر حسب UTC هستند. خطاهای سرویس به شکل پاک‌سازی‌شده نمایش داده می‌شوند؛ کلید API و محتوای حساس پاسخ ذخیره نمی‌شود.</p>
        </div><script>document.querySelectorAll('.vto-delete-job').forEach(function(button){button.addEventListener('click',function(event){if(!window.confirm('درخواست و عکس‌های محلی آن برای همیشه حذف شوند؟')){event.preventDefault();}});});document.querySelectorAll('.vto-retry-job').forEach(function(button){button.addEventListener('click',function(event){if(!window.confirm('این عمل یک درخواست ساخت تصویر جدید به AvalAI می‌فرستد و ممکن است دوباره هزینه داشته باشد. آیا تاریخچه و مصرف درخواست قبلی را بررسی کرده‌اید و می‌خواهید ادامه دهید؟')){event.preventDefault();}});});</script>
        <?php
    }
}
