#!/usr/bin/env bash
# Isolated WordPress development tools. No sudo, worktrees, or merchant credentials.
set -euo pipefail
vto_tools_dir="${VTO_TOOLS_DIR:-/workspace/.vto-tools}"
vto_dev_dir="${VTO_DEV_DIR:-/workspace/.vto-dev}"
vto_plugin_dir="${VTO_PLUGIN_DIR:-/workspace/virtual-tryon-wp}"
vto_dev_url="${VTO_DEV_URL:-http://127.0.0.1:8088}"
export VTO_TOOLS_DIR="$vto_tools_dir" VTO_DEV_DIR="$vto_dev_dir" VTO_PLUGIN_DIR="$vto_plugin_dir" VTO_DEV_URL="$vto_dev_url"
mkdir -p "$vto_tools_dir" "$vto_dev_dir"
if [[ ! -f "$vto_plugin_dir/virtual-tryon-wp.php" ]]; then
    echo 'Virtual Try-On Studio checkout is missing.' >&2; exit 1
fi
if [[ ! -x "$vto_tools_dir/bin/php" ]] || ! "$vto_tools_dir/bin/php" -r 'exit(extension_loaded("gd") && extension_loaded("pdo_sqlite") && extension_loaded("curl") ? 0 : 1);'; then
    mkdir -p "$vto_tools_dir/apt/lists/partial" "$vto_tools_dir/apt/cache/archives/partial" "$vto_tools_dir/debs" "$vto_tools_dir/root" "$vto_tools_dir/bin"
    cat > "$vto_tools_dir/sources.list" <<'SOURCES'
deb https://deb.debian.org/debian trixie main
deb https://deb.debian.org/debian-security trixie-security main
SOURCES
    vto_apt_options=(-o "Dir::Etc::sourcelist=$vto_tools_dir/sources.list" -o Dir::Etc::sourceparts=- -o "Dir::State::lists=$vto_tools_dir/apt/lists" -o "Dir::Cache=$vto_tools_dir/apt/cache")
    apt-get "${vto_apt_options[@]}" update
    ( cd "$vto_tools_dir/debs"; apt-get "${vto_apt_options[@]}" download php8.4-cli php8.4-common php8.4-sqlite3 php8.4-gd php8.4-mbstring php8.4-xml php8.4-curl libargon2-1 )
    for vto_package in "$vto_tools_dir"/debs/*.deb; do dpkg-deb -x "$vto_package" "$vto_tools_dir/root"; done
    cat > "$vto_tools_dir/php.ini" <<INI
extension_dir=$vto_tools_dir/root/usr/lib/php/20240924
extension=ctype
extension=dom
extension=fileinfo
extension=gd
extension=iconv
extension=mbstring
extension=pdo
extension=pdo_sqlite
extension=sqlite3
extension=tokenizer
extension=xml
extension=xmlreader
extension=xmlwriter
extension=curl
extension=phar
memory_limit=512M
upload_max_filesize=12M
post_max_size=16M
INI
    cat > "$vto_tools_dir/bin/php" <<WRAPPER
#!/bin/sh
export LD_LIBRARY_PATH='$vto_tools_dir/root/usr/lib/x86_64-linux-gnu'\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}
exec '$vto_tools_dir/root/usr/bin/php8.4' -c '$vto_tools_dir/php.ini' "\$@"
WRAPPER
    chmod +x "$vto_tools_dir/bin/php"
fi
vto_php="$vto_tools_dir/bin/php"
"$vto_php" -r 'foreach(["gd","pdo_sqlite","mbstring","curl","fileinfo","dom"] as $m){if(!extension_loaded($m)){fwrite(STDERR,"Missing PHP module: $m\n");exit(1);}}'
vto_checkout() {
    local vto_url="$1" vto_commit="$2" vto_path="$3"
    if [[ ! -d "$vto_path" ]]; then
        git init -q "$vto_path"
        git -C "$vto_path" remote add origin "$vto_url"
        git -C "$vto_path" fetch --depth 1 origin "$vto_commit"
        git -C "$vto_path" -c advice.detachedHead=false checkout --detach FETCH_HEAD
    fi
    if [[ "$(git -C "$vto_path" rev-parse HEAD)" != "$vto_commit" ]]; then
        echo "Unexpected dependency checkout at $vto_path; preserving it without reset." >&2; exit 1
    fi
}
vto_checkout https://github.com/WordPress/WordPress.git b16cd68ea199838d8f9daf0ff7e3f35042ba0ad0 "$vto_dev_dir/wordpress"
vto_checkout https://github.com/WordPress/sqlite-database-integration.git c156cf1066d1f0fce65ef8769debf0a2480bef65 "$vto_dev_dir/sqlite-database-integration"
vto_wp="$vto_dev_dir/wordpress"
vto_sqlite="$vto_dev_dir/sqlite-database-integration/packages/plugin-sqlite-database-integration"
vto_link() {
    local vto_target="$1" vto_dest="$2"
    if [[ -e "$vto_dest" || -L "$vto_dest" ]]; then
        if [[ "$(realpath "$vto_dest")" != "$(realpath "$vto_target")" ]]; then echo "Preserving unexpected path $vto_dest" >&2; exit 1; fi
    else ln -s "$vto_target" "$vto_dest"; fi
}
vto_link "$vto_sqlite" "$vto_wp/wp-content/plugins/sqlite-database-integration"
vto_link "$vto_plugin_dir" "$vto_wp/wp-content/plugins/virtual-tryon-wp"
if [[ ! -f "$vto_wp/wp-content/db.php" ]]; then cp "$vto_sqlite/db.copy" "$vto_wp/wp-content/db.php"; fi
if ! cmp -s "$vto_sqlite/db.copy" "$vto_wp/wp-content/db.php"; then echo 'Existing DB drop-in differs; preserving it.' >&2; exit 1; fi
if [[ ! -d "$vto_wp/wp-content/plugins/woocommerce" ]]; then
    if [[ ! -f "$vto_dev_dir/woocommerce.zip" ]]; then
        curl --fail --show-error --location --proto '=https' --tlsv1.2 --max-time 120 -o "$vto_dev_dir/woocommerce.zip" https://github.com/woocommerce/woocommerce/releases/download/11.2.1/woocommerce.zip
    fi
    echo "3066f85e46d1c1f6451296da0300b459963b504fd0d2f75869701ee744833aaf  $vto_dev_dir/woocommerce.zip" | sha256sum --check -
    unzip -q "$vto_dev_dir/woocommerce.zip" -d "$vto_wp/wp-content/plugins"
fi
if ! rg -q 'Version: 11\.2\.1' "$vto_wp/wp-content/plugins/woocommerce/woocommerce.php"; then echo 'Unexpected WooCommerce version; preserving it.' >&2; exit 1; fi
# Generate new local-only salts on a fresh install. Existing wp-config.php is untouched.
"$vto_php" <<'PHP'
<?php
$root=getenv('VTO_DEV_DIR').'/wordpress';
if (!file_exists($root.'/wp-config.php')) {
    $constants=['DB_NAME'=>'vto_dev','DB_USER'=>'','DB_PASSWORD'=>'','DB_HOST'=>'localhost','DB_CHARSET'=>'utf8mb4','DB_COLLATE'=>'','WP_HOME'=>getenv('VTO_DEV_URL'),'WP_SITEURL'=>getenv('VTO_DEV_URL'),'DISABLE_WP_CRON'=>true,'WP_DEBUG'=>true,'WP_DEBUG_DISPLAY'=>false,'WP_DEBUG_LOG'=>getenv('VTO_DEV_DIR').'/debug.log','WP_ENVIRONMENT_TYPE'=>'local'];
    foreach(['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $name){$constants[$name]=bin2hex(random_bytes(32));}
    $config="<?php\n";
    foreach($constants as $name=>$value){$config.='define('.var_export($name,true).', '.var_export($value,true).");\n";}
    $config.="\$table_prefix='wp_';\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire_once ABSPATH.'wp-settings.php';\n";
    file_put_contents($root.'/wp-config.php',$config,LOCK_EX);chmod($root.'/wp-config.php',0600);
}
PHP
# No password is stored in scripts or printed. Local test authentication uses WP CLI APIs.
"$vto_php" <<'PHP'
<?php
define('WP_INSTALLING',true);
require getenv('VTO_DEV_DIR').'/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
$fresh=!is_blog_installed();
// Local development intentionally has no outbound mail transport.
add_filter('pre_wp_mail', static function(){return true;});
if($fresh){wp_install('پرو مجازی — محیط توسعه','vto_admin','developer@example.invalid',true,'',bin2hex(random_bytes(24)),'fa_IR');update_option('vto_dev_seed_pending',true,false);}else{wp_upgrade();}
PHP
"$vto_php" <<'PHP'
<?php
require getenv('VTO_DEV_DIR').'/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
foreach(['woocommerce/woocommerce.php','virtual-tryon-wp/virtual-tryon-wp.php'] as $plugin){$result=activate_plugin($plugin);if(is_wp_error($result)){fwrite(STDERR,$result->get_error_message().PHP_EOL);exit(1);}}
PHP
"$vto_php" <<'PHP'
<?php
require getenv('VTO_DEV_DIR').'/wordpress/wp-load.php';
VTO\Jobs::install();
if(get_option('vto_dev_seed_pending')){
    $im=imagecreatetruecolor(600,600);imagefill($im,0,0,imagecolorallocate($im,108,60,129));$gold=imagecolorallocate($im,235,202,139);$cream=imagecolorallocate($im,247,236,217);imagesetthickness($im,14);imagerectangle($im,18,18,581,581,$gold);imagesetthickness($im,3);imagerectangle($im,42,42,557,557,$cream);for($y=100;$y<550;$y+=100){for($x=100;$x<550;$x+=100){imagefilledellipse($im,$x,$y,24,42,$cream);imagefilledellipse($im,$x-17,$y+12,30,15,$gold);}}ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);
    $up=wp_upload_bits('scarf-demo.png',null,$png);if(!empty($up['error'])){fwrite(STDERR,$up['error']);exit(1);}
    $aid=wp_insert_attachment(['post_title'=>'روسری بنفش','post_status'=>'inherit','post_mime_type'=>'image/png'],$up['file']);update_attached_file($aid,$up['file']);
    $p=new WC_Product_Simple();$p->set_name('روسری طرح بنفشه');$p->set_status('publish');$p->set_regular_price(250000);$p->set_image_id($aid);$pid=$p->save();
    $s=VTO\Settings::defaults();$s['product_ids']=[$pid];$s['catalog_source']='designs';$s['manual_products']=[['id'=>'demo-floral','name'=>'طرح باغ بنفشه','image_id'=>$aid,'active'=>true]];update_option('vto_settings',$s,false);
    $page=wp_insert_post(['post_title'=>'اتاق پرو مجازی','post_name'=>'virtual-tryon','post_content'=>'[virtual_tryon]','post_status'=>'publish','post_type'=>'page']);update_option('show_on_front','page');update_option('page_on_front',$page);delete_option('vto_dev_seed_pending');
}
echo 'Prepared WordPress '.get_bloginfo('version').' / WooCommerce '.WC_VERSION.' / PHP '.PHP_VERSION.PHP_EOL;
PHP
# Unit/provider mocks never call a paid provider. Tests restore storefront settings.
VTO_WP_ROOT="$vto_wp" "$vto_php" "$vto_plugin_dir/tests/integration.php"
VTO_WP_ROOT="$vto_wp" "$vto_php" "$vto_plugin_dir/tests/provider-results.php"
VTO_WP_ROOT="$vto_wp" "$vto_php" "$vto_plugin_dir/tests/raw-designs.php"
vto_port="$("$vto_php" -r '$u=parse_url(getenv("VTO_DEV_URL"));echo $u["port"] ?? 80;')"
echo "Start: $vto_php -S 127.0.0.1:$vto_port -t $vto_wp"
echo "HTTP checks: VTO_WP_ROOT=$vto_wp VTO_PHP=$vto_php python3 $vto_plugin_dir/tests/http-smoke.py"
