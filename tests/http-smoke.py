#!/usr/bin/env python3
"""Real multipart REST checks on disposable WP (local/development only).
Start PHP server, activate plugin + WooCommerce, then:
VTO_WP_ROOT=/path/to/wordpress VTO_PHP=php python3 tests/http-smoke.py
"""
import base64
import json
import os
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = os.environ.get('VTO_WP_ROOT', '')
PHP = os.environ.get('VTO_PHP', 'php')
if not ROOT:
    raise SystemExit('Set VTO_WP_ROOT to a disposable WordPress install.')


def php(code, payload=None):
    source = "require getenv('VTO_WP_ROOT').'/wp-load.php'; if(!in_array(wp_get_environment_type(),['local','development'],true)){fwrite(STDERR,'Refusing non-development database');exit(2);}" + code
    result = subprocess.run([PHP, '-r', source], input=json.dumps(payload or {}), capture_output=True, text=True, check=True)
    return json.loads(result.stdout or 'null')


state = php("""
$s=VTO\\Settings::get();
$im=imagecreatetruecolor(128,128);imagefill($im,0,0,imagecolorallocate($im,100,80,150));ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);
$up=wp_upload_bits('vto-http-fixture.png',null,$png);
$aid=wp_insert_attachment(['post_title'=>'VTO HTTP fixture','post_status'=>'inherit','post_mime_type'=>'image/png'],$up['file']);update_attached_file($aid,$up['file']);
$p=new WC_Product_Simple();$p->set_name('روسری تست HTTP');$p->set_status('publish');$p->set_regular_price(100);$p->set_image_id($aid);$pid=$p->save();
$next=VTO\\Settings::defaults();$next['mode']='demo';$next['product_ids']=[$pid];$next['rate_limit']=50;$next['global_rate_limit']=1000;update_option('vto_settings',$next,false);
VTO\\Jobs::install();
$manager=WP_Session_Tokens::get_instance(1);$exp=time()+3600;$token=$manager->create($exp);$cookie=wp_generate_auth_cookie(1,$exp,'logged_in',$token);wp_set_current_user(1);
echo wp_json_encode(['old_settings'=>$s,'product_id'=>$pid,'attachment_id'=>$aid,'image'=>base64_encode($png),'base'=>home_url('/'),'admin_cookie'=>LOGGED_IN_COOKIE.'='.$cookie,'session_token'=>$token,'admin_nonce'=>wp_create_nonce('wp_rest')]);
""")
BASE = os.environ.get('VTO_BASE_URL', state['base']).rstrip('/') + '/'
created_tokens = []
checks = 0


def check(value, message):
    global checks
    if not value:
        raise AssertionError(message)
    checks += 1
    print('PASS ' + message)


def request(route, fields=None, image=None, nonce=None, origin=None, cookie=None, extra=None):
    url = BASE + '?rest_route=' + urllib.parse.quote('/vto/v1/' + route, safe='/')
    if extra:
        url += '&' + urllib.parse.urlencode(extra)
    headers = {}
    if nonce:
        headers['X-WP-Nonce'] = nonce
    if origin:
        headers['Origin'] = origin
    if cookie:
        headers['Cookie'] = cookie
    body = None
    if fields is not None:
        boundary = 'vto-' + uuid.uuid4().hex
        parts = []
        for key, value in fields.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        if image is not None:
            parts.extend([f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="person.png"\r\nContent-Type: image/png\r\n\r\n'.encode(), image, b'\r\n'])
        parts.append(f'--{boundary}--\r\n'.encode())
        body = b''.join(parts)
        headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
    req = urllib.request.Request(url, data=body, headers=headers)
    try:
        response = urllib.request.urlopen(req, timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    content = response.read()
    if 'application/json' in response.headers.get('Content-Type', ''):
        content = json.loads(content)
    return response.status, content, response.headers


def ajax_session(cookie=None):
    headers = {'Cookie': cookie} if cookie else {}
    req = urllib.request.Request(BASE + 'wp-admin/admin-ajax.php?action=vto_session', headers=headers)
    with urllib.request.urlopen(req, timeout=20) as response:
        return response.status, json.loads(response.read()), response.headers


try:
    status, session, headers = request('session')
    check(status == 200 and session.get('nonce'), 'Guest can obtain a fresh nonce')
    check('no-store' in headers.get('Cache-Control', ''), 'Session response is never cached')
    status, ajax_guest, ajax_headers = ajax_session()
    check(status == 200 and ajax_guest.get('nonce'), 'Guest refreshes REST nonce through uncached AJAX without bootstrap nonce')
    check('no-store' in ajax_headers.get('Cache-Control', ''), 'Guest AJAX nonce response is never cached')
    nonce = ajax_guest['nonce']
    fields = {'product_id': 'wc:' + str(state['product_id']), 'consent': '1', 'page_url': BASE + '?page_id=123'}
    image = base64.b64decode(state['image'])
    status, error, _ = request('jobs', fields, image)
    check(status == 403, 'Upload without nonce is rejected')
    status, error, _ = request('jobs', fields, image, nonce, 'https://other.example')
    check(status == 403 and error['code'] == 'origin_forbidden', 'Foreign origin is rejected')
    without_consent = dict(fields, consent='0')
    status, error, _ = request('jobs', without_consent, image, nonce)
    check(status == 400 and error['code'] == 'consent_required', 'Explicit customer consent is required')
    status, error, _ = request('jobs', dict(fields, product_id='wc:9999999'), image, nonce)
    check(status == 400 and error['code'] == 'invalid_product', 'Crafted product ID cannot bypass allowlist')
    status, error, _ = request('jobs', fields, b'<?php echo 1; ?>', nonce)
    check(status == 400 and error['code'] in ('image_type', 'image_dimensions'), 'Actual multipart upload rejects executable content')
    status, result, _ = request('jobs', fields, image, nonce)
    check(status == 202 and result['status'] == 'queued', 'Actual multipart image upload creates queued request')
    token = result['token']; created_tokens.append(token)
    check('page_id=123' in result['tracking_url'] and 'vto_job=' in result['tracking_url'], 'Private recovery URL preserves WordPress plain page ID')
    status, pending, _ = request('jobs/' + token)
    check(status == 200 and pending['status'] == 'queued' and pending['image_url'] is None, 'Polling a queue does not create output or trigger paid work')
    check('input_path' not in pending and 'prompt' not in pending, 'Public status contains no private paths or prompts')
    php("$d=json_decode(stream_get_contents(STDIN),true);$j=VTO\\Jobs::by_token($d['token']);VTO\\Jobs::process($j['id']);echo 'true';", {'token': token})
    status, complete, _ = request('jobs/' + token)
    check(status == 200 and complete['status'] == 'completed' and complete['mode'] == 'demo', 'Worker finishes demo and exposes simulated status')
    status, output, headers = request('jobs/' + token + '/image')
    check(status == 200 and headers.get('Content-Type') == 'image/jpeg' and output[:2] == b'\xff\xd8', 'Customer result streams private re-encoded image')
    check('no-store' in headers.get('Cache-Control', '') and headers.get('Referrer-Policy') == 'no-referrer', 'Image response prevents caching and tracking token referrer leaks')
    status, output, headers = request('jobs/' + token + '/image', extra={'download': '1'})
    check('attachment' in headers.get('Content-Disposition', ''), 'Download uses attachment response')
    status, error, _ = request('jobs/' + '0' * 64)
    check(status == 404, 'Invalid tracking token returns 404')
    job_id = php("$d=json_decode(stream_get_contents(STDIN),true);echo wp_json_encode(VTO\\Jobs::by_token($d['token'])['id']);", {'token': token})
    status, error, _ = request('admin/jobs/' + str(job_id) + '/image', extra={'kind': 'input'})
    check(status in (401, 403), 'Private input image requires administrator authorization')
    # Produce a nonce tied to the exact logged-in session cookie, not a CLI-only nonce.
    auth_nonce = php("$d=json_decode(stream_get_contents(STDIN),true);$_COOKIE[LOGGED_IN_COOKIE]=substr($d['cookie'],strpos($d['cookie'],'=')+1);wp_set_current_user(1);echo wp_json_encode(wp_create_nonce('wp_rest'));", {'cookie': state['admin_cookie']})
    status, admin_input, _ = request('admin/jobs/' + str(job_id) + '/image', nonce=auth_nonce, cookie=state['admin_cookie'], extra={'kind': 'input'})
    check(status == 200 and isinstance(admin_input, bytes), 'Authorized administrator can view input image')
    php("$s=VTO\\Settings::get();$s['require_login']=true;update_option('vto_settings',$s,false);echo 'true';")
    status, error, _ = request('jobs', fields, image, nonce)
    check(status == 401 and error['code'] == 'login_required', 'Store can disallow guest submissions')
    status, logged_session, _ = request('session', nonce=auth_nonce, cookie=state['admin_cookie'])
    check(status == 200 and logged_session['nonce'], 'Authenticated session refresh preserves valid REST authentication')
    status, ajax_auth, ajax_headers = ajax_session(state['admin_cookie'])
    check(status == 200 and ajax_auth.get('nonce'), 'Logged-in cookie refreshes REST nonce through AJAX without bootstrap nonce')
    check('no-store' in ajax_headers.get('Cache-Control', '') and ajax_auth['nonce'] != nonce, 'Authenticated AJAX nonce is uncached and distinct from guest nonce')
    status, logged_job, _ = request('jobs', fields, image, ajax_auth['nonce'], cookie=state['admin_cookie'])
    check(status == 202, 'Logged-in customer can submit when login is required')
    created_tokens.append(logged_job['token'])
    php("$d=json_decode(stream_get_contents(STDIN),true);$j=VTO\\Jobs::by_token($d['token']);$GLOBALS['wpdb']->update(VTO\\Jobs::table(),['expires_at'=>gmdate('Y-m-d H:i:s',time()-60)],['id'=>$j['id']]);echo 'true';", {'token': token})
    status, expired, _ = request('jobs/' + token + '/image')
    check(status == 404, 'Expired image is denied immediately before scheduled cleanup')
    print(f'\n{checks} HTTP assertions passed. No paid API calls were made.')
finally:
    php("""
$d=json_decode(stream_get_contents(STDIN),true);
foreach($d['tokens'] as $t){$hash=hash('sha256',$t);$id=$GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT id FROM '.VTO\\Jobs::table().' WHERE token_hash=%s',$hash));if($id){VTO\\Jobs::delete((int)$id);}}
wp_delete_post($d['state']['product_id'],true);wp_delete_attachment($d['state']['attachment_id'],true);
update_option('vto_settings',$d['state']['old_settings'],false);
WP_Session_Tokens::get_instance(1)->destroy($d['state']['session_token']);echo 'true';
""", {'tokens': created_tokens, 'state': state})
