<?php
/**
 * Provider response and non-billable result recovery integration checks.
 * Uses real disposable WordPress/WooCommerce + HTTP interception. No live API.
 * VTO_WP_ROOT=/absolute/path/to/wordpress php tests/provider-results.php
 */
$bootstrap = getenv('VTO_WP_LOAD') ?: (getenv('VTO_WP_ROOT') ? rtrim(getenv('VTO_WP_ROOT'), '/').'/wp-load.php' : '');
if (!$bootstrap || !is_file($bootstrap)) { fwrite(STDERR, "Set VTO_WP_ROOT to disposable WordPress.\n"); exit(2); }
require $bootstrap;
if (!in_array(wp_get_environment_type(), ['local','development'], true)) { fwrite(STDERR, "Refusing non-development database.\n"); exit(2); }
if (!class_exists('VTO\\Provider_Result') || !class_exists('WC_Product_Simple')) { fwrite(STDERR, "Activate the latest plugin + WooCommerce first.\n"); exit(2); }
$passed = 0;
function result_check($condition, $message) {
    global $passed;
    if (!$condition) { throw new RuntimeException($message); }
    $passed++;
    echo "PASS $message\n";
}
function response_fixture($body, $code = 200, $headers = ['content-type'=>'application/json']) {
    return ['headers'=>$headers, 'response'=>['code'=>$code,'message'=>'Mock'], 'body'=>is_string($body)?$body:wp_json_encode($body), 'cookies'=>[]];
}
$old_settings = get_option('vto_settings', null);
$old_user = get_current_user_id();
$product_id = 0;
$attachment_id = 0;
$created_jobs = [];
$temporary_paths = [];
$exit_code = 0;
$filter = null;
try {
    wp_set_current_user(1);
    $settings = VTO\Settings::defaults();
    $settings['mode']='avalai';
    $settings['api_key']='provider-result-test-not-a-real-key';
    $settings['global_rate_limit']=1000;
    update_option('vto_settings',$settings,false);
    VTO\Jobs::install();
    $image=imagecreatetruecolor(128,128);imagefill($image,0,0,imagecolorallocate($image,110,100,160));ob_start();imagepng($image);$png=ob_get_clean();imagedestroy($image);
    $result_image=imagecreatetruecolor(128,128);imagefill($result_image,0,0,imagecolorallocate($result_image,30,175,90));ob_start();imagepng($result_image);$result_png=ob_get_clean();imagedestroy($result_image);
    $upload=wp_upload_bits('vto-result-product.png',null,$png);
    result_check(empty($upload['error']), 'Product reference fixture prepared');
    $attachment_id=wp_insert_attachment(['post_title'=>'Result test reference','post_mime_type'=>'image/png','post_status'=>'inherit'],$upload['file']);
    update_attached_file($attachment_id,$upload['file']);
    $product=new WC_Product_Simple();$product->set_name('روسری تست بازیابی');$product->set_status('publish');$product->set_regular_price(100);$product->set_image_id($attachment_id);$product_id=$product->save();
    $settings['product_ids']=[$product_id];update_option('vto_settings',$settings,false);
    $catalog=VTO\Catalog::find('wc:'.$product_id);
    $url='https://1.1.1.1/vto-test-result.png?signature=PRIVATE_RESULT_SIGNATURE';
    $post_count=0;
    $get_count=0;
    $download_mode='image';
    $post_response=response_fixture(['data'=>[['url'=>$url]]]);
    $get_arguments=[];
    $active_job_id=0;
    $reference_visible_at_fetch=false;
    $filter=function($pre,$args,$target) use (&$post_count,&$get_count,&$download_mode,&$post_response,&$get_arguments,&$active_job_id,&$reference_visible_at_fetch,$png,$result_png) {
        if($target===VTO\Provider::ENDPOINT){
            $post_count++;
            result_check(strtoupper($args['method']??'POST')==='POST', 'Billable request uses expected generation endpoint');
            return $post_response;
        }
        if(str_starts_with($target,'https://1.1.1.1/')){
            $get_count++;$get_arguments=$args;
            if($active_job_id){$in_flight=VTO\Jobs::by_id($active_job_id);$reference_visible_at_fetch=!empty($in_flight['reference_path']) && VTO\Storage::owns($in_flight['reference_path']);}
            if($download_mode==='transport'){return new WP_Error('http_request_failed','secret transport payload must not be logged');}
            if($download_mode==='html'){return response_fixture('<html>not an image PRIVATE_RESULT_SIGNATURE</html>',200,['content-type'=>'text/html']);}
            if($download_mode==='expired'){return response_fixture('expired signed URL PRIVATE_RESULT_SIGNATURE',403,['content-type'=>'text/plain']);}
            if($download_mode==='redirect'){return response_fixture('',302,['location'=>'https://127.0.0.1/private']);}
            if($download_mode==='public_redirect' && !str_contains($target,'/safe-final.png')){return response_fixture('',302,['location'=>'https://1.1.1.1/safe-final.png']);}
            if($download_mode==='redirect_loop'){return response_fixture('',302,['location'=>'https://1.1.1.1/loop.png']);}
            return response_fixture($download_mode==='unchanged' ? $png : $result_png,200,['content-type'=>'image/png']);
        }
        // A bug must not turn this suite into any real network request.
        return new WP_Error('vto_test_unexpected_network','Unexpected outbound request blocked by test.');
    };
    add_filter('pre_http_request',$filter,10,3);
    $create=function() use ($catalog,$png,&$created_jobs,&$temporary_paths) {
        $path=VTO\Storage::save($png);$temporary_paths[]=$path;
        if(is_wp_error($path)){throw new RuntimeException('Could not save private test image');}
        $created=VTO\Jobs::create($catalog,$path);
        if(is_wp_error($created)){throw new RuntimeException('Could not queue test request');}
        $created_jobs[]=$created['id'];return $created;
    };
    $first=$create();$active_job_id=$first['id'];
    VTO\Jobs::process($first['id']);
    $row=VTO\Jobs::by_id($first['id']);
    result_check($row['status']==='completed' && $post_count===1 && $get_count===1, 'Standard URL result completes with one paid POST and one result GET');
    result_check($reference_visible_at_fetch, 'Signed result reference is durably committed before its first network download');
    result_check(VTO\Storage::owns($row['output_path']) && file_get_contents($row['output_path'])===$result_png, 'Downloaded provider image is saved in private storage');
    result_check(strtoupper($get_arguments['method']??'GET')==='GET' && empty($get_arguments['headers']['Authorization']) && empty($get_arguments['headers']['Cookie']) && empty($get_arguments['cookies']), 'Result GET sends neither provider authorization nor cookies');
    result_check(!empty($get_arguments['sslverify']) && !empty($get_arguments['reject_unsafe_urls']) && $get_arguments['redirection']<=3 && $get_arguments['limit_response_size']<=VTO\Provider::MAX_IMAGE_BYTES+1, 'Result download preserves TLS and bounds redirects and response bytes');
    result_check($get_arguments['timeout'] > 30 && $get_arguments['timeout'] <= VTO\Provider_Result::DOWNLOAD_TIMEOUT, 'Result download is not cut off by the previous 30-second deadline');
    $status_request=new WP_REST_Request('GET','/vto/v1/jobs/'.$first['token']);$status_request['token']=$first['token'];
    $public=VTO\Rest::status($status_request)->get_data();
    result_check(!str_contains(wp_json_encode($public),'PRIVATE_RESULT_SIGNATURE') && !array_key_exists('reference_path',$public), 'Public job status never exposes signed result URL or private reference path');
    VTO\Jobs::process($first['id']);
    result_check($post_count===1 && (int)VTO\Jobs::by_id($first['id'])['attempts']===1, 'Completed URL request cannot be accidentally submitted twice');
    $download_mode='unchanged';
    $unchanged=$create();$active_job_id=$unchanged['id'];
    $before_unchanged=[$post_count,$get_count];
    VTO\Jobs::process($unchanged['id']);
    $unchanged_row=VTO\Jobs::by_id($unchanged['id']);
    $unchanged_status_request=new WP_REST_Request('GET','/vto/v1/jobs/'.$unchanged['token']);$unchanged_status_request['token']=$unchanged['token'];
    $unchanged_public=VTO\Rest::status($unchanged_status_request)->get_data();
    $unchanged_retry=VTO\Jobs::retry($unchanged['id']);
    result_check($unchanged_row['status']==='failed' && $unchanged_row['error_code']==='vto_provider_unchanged' && empty($unchanged_row['reference_path']), 'URL output matching the customer photo is rejected and its saved provider link is discarded');
    result_check($unchanged_public['status']==='failed' && is_wp_error($unchanged_retry) && $unchanged_retry->get_error_code()==='unchanged_requires_new_request', 'Unchanged URL output cannot be delivered through browser fallback or resubmitted for another charge');
    result_check([$post_count,$get_count]===[$before_unchanged[0]+1,$before_unchanged[1]+1], 'Unchanged URL validation uses one generation POST and one result GET only');
    $download_mode='transport';
    $failed=$create();VTO\Jobs::process($failed['id']);
    $failed_row=VTO\Jobs::by_id($failed['id']);
    result_check($failed_row['status']==='failed' && !empty($failed_row['reference_path']) && VTO\Storage::owns($failed_row['reference_path']), 'Failed result GET retains a private recoverable provider reference');
    result_check(VTO\Storage::read_reference($failed_row['reference_path'])===$url, 'Retained result reference is readable through private storage only');
    result_check((fileperms($failed_row['reference_path']) & 0777) === 0600, 'Signed result reference is readable only by the server account');
    result_check(!str_contains($failed_row['error_message'].$failed_row['error_detail'],'PRIVATE_RESULT_SIGNATURE') && !str_contains($failed_row['error_detail'],'secret transport payload'), 'Download failure diagnostic redacts URL signatures and raw transport messages');
    $remote_counts=[$post_count,$get_count];
    $remote_status_request=new WP_REST_Request('GET','/vto/v1/jobs/'.$failed['token']);$remote_status_request['token']=$failed['token'];
    $remote_public=VTO\Rest::status($remote_status_request)->get_data();
    result_check($remote_public['status']==='failed' && $remote_public['image_url']===null && !str_contains(wp_json_encode($remote_public),'PRIVATE_RESULT_SIGNATURE') && !str_contains(wp_json_encode($remote_public),$failed_row['reference_path']), 'Unverified remote result is not presented to the customer as completed');
    $remote_image_request=new WP_REST_Request('GET','/vto/v1/jobs/'.$failed['token'].'/image');$remote_image_request['token']=$failed['token'];
    $remote_redirect=VTO\Rest::image($remote_image_request);
    result_check(is_wp_error($remote_redirect), 'Customer capability cannot bypass local output validation through the provider redirect');
    result_check([$post_count,$get_count]===$remote_counts && VTO\Jobs::by_id($failed['id'])['status']==='failed', 'Blocked customer result performs no server download, generation or job mutation');
    wp_set_current_user(0);
    $forbidden_admin=rest_do_request(new WP_REST_Request('GET','/vto/v1/admin/jobs/'.$failed['id'].'/provider-image'));
    wp_set_current_user(1);
    $allowed_admin=rest_do_request(new WP_REST_Request('GET','/vto/v1/admin/jobs/'.$failed['id'].'/provider-image'));
    result_check($forbidden_admin->get_status()>=400 && $allowed_admin->get_status()===302 && ($allowed_admin->get_headers()['Location']??'')===$url, 'Direct provider result route requires administrator capability');
    $saved_reference_bytes=file_get_contents($failed_row['reference_path']);
    file_put_contents($failed_row['reference_path'],wp_json_encode(['url'=>'https://127.0.0.1/private']),LOCK_EX);
    $blocked_remote=VTO\Rest::image($remote_image_request);
    file_put_contents($failed_row['reference_path'],$saved_reference_bytes,LOCK_EX);
    result_check(is_wp_error($blocked_remote), 'Tampered private-network result reference cannot redirect a browser');
    result_check(is_wp_error(VTO\Jobs::retry($failed['id'])), 'Retained result must be recovered rather than accidentally regenerated at extra cost');
    $download_mode='transport';
    $browser_import=$create();VTO\Jobs::process($browser_import['id']);
    $browser_import_before=[$post_count,$get_count];
    $browser_imported=VTO\Jobs::import_result($browser_import['id'],$result_png);
    $browser_import_row=VTO\Jobs::by_id($browser_import['id']);
    result_check($browser_imported===true && $browser_import_row['status']==='completed' && VTO\Storage::owns($browser_import_row['output_path']) && file_get_contents($browser_import_row['output_path'])===$result_png, 'Administrator can store the browser-downloaded existing result after host CDN timeout');
    result_check([$post_count,$get_count]===$browser_import_before && (int)$browser_import_row['attempts']===1 && (int)$browser_import_row['recovery_attempts']===0, 'Browser result import performs no generation POST or result GET and does not change attempt counters');
    result_check(empty($browser_import_row['reference_path']) && is_wp_error(VTO\Jobs::import_result($browser_import['id'],$result_png)), 'Successful browser import removes the signed reference and cannot be repeated');
    $unchanged_import=$create();VTO\Jobs::process($unchanged_import['id']);
    $unchanged_import_before=[$post_count,$get_count];
    $unchanged_import_result=VTO\Jobs::import_result($unchanged_import['id'],$png);
    $unchanged_import_row=VTO\Jobs::by_id($unchanged_import['id']);
    result_check(is_wp_error($unchanged_import_result) && $unchanged_import_result->get_error_code()==='vto_provider_unchanged' && $unchanged_import_row['status']==='failed' && empty($unchanged_import_row['reference_path']), 'Browser import rejects the unchanged customer photo and removes its provider reference');
    result_check([$post_count,$get_count]===$unchanged_import_before && is_wp_error(VTO\Jobs::retry($unchanged_import['id'])), 'Rejected unchanged browser result cannot trigger another paid request');
    $posts_before_recover=$post_count;$gets_before_recover=$get_count;
    $download_mode='image';
    $recovered=VTO\Jobs::recover($failed['id']);
    $recovered_row=VTO\Jobs::by_id($failed['id']);
    result_check(!is_wp_error($recovered) && $recovered_row['status']==='completed' && $post_count===$posts_before_recover && $get_count===$gets_before_recover+1, 'Recovery downloads existing result with GET and never repeats paid generation');
    result_check((int)$recovered_row['attempts']===1 && (int)$recovered_row['recovery_attempts']===1, 'Generation and recovery attempts are counted separately');
    result_check(empty($recovered_row['reference_path']) && !file_exists($failed_row['reference_path']), 'Successful recovery deletes its no-longer-needed signed reference');
    result_check(is_wp_error(VTO\Jobs::recover($failed['id'])) && $post_count===$posts_before_recover, 'Completed request cannot start a second recovery');
    $download_mode='expired';
    $capped=$create();VTO\Jobs::process($capped['id']);
    $cap_posts=$post_count;
    for($i=0;$i<3;$i++){VTO\Jobs::recover($capped['id']);}
    result_check(is_wp_error(VTO\Jobs::recover($capped['id'])) && (int)VTO\Jobs::by_id($capped['id'])['recovery_attempts']===3 && $post_count===$cap_posts, 'Recovery limit bounds repeated GET failures without extra paid POST');
    $expiry_ref=VTO\Jobs::by_id($capped['id'])['reference_path'];
    $GLOBALS['wpdb']->update(VTO\Jobs::table(),['expires_at'=>gmdate('Y-m-d H:i:s',time()-60)],['id'=>$capped['id']]);
    $gets_before_expiry=$get_count;
    result_check(is_wp_error(VTO\Jobs::recover($capped['id'])) && $get_count===$gets_before_expiry, 'Expired request cannot download retained provider result');
    VTO\Jobs::maintenance();
    result_check(!file_exists($expiry_ref) && VTO\Jobs::by_id($capped['id'])===null, 'Expiration cleanup deletes private result reference with request');
    $active_job_id=0;
    $download_mode='html';
    $bad_output=VTO\Provider_Result::fetch($url);
    result_check(is_wp_error($bad_output), 'HTML result body is rejected instead of saved as an image');
    $download_mode='redirect';
    $blocked_redirect = VTO\Provider_Result::fetch($url);
    result_check(is_wp_error($blocked_redirect), 'Redirect to private-network destination is rejected');
    $download_mode='public_redirect';$before_redirect=$get_count;$redirected=VTO\Provider_Result::fetch($url);
    result_check(!is_wp_error($redirected) && $redirected['bytes']===$result_png && $get_count===$before_redirect+2, 'Safe public HTTPS redirect is followed with bounded GET-only requests');
    $download_mode='redirect_loop';$before_loop=$get_count;
    result_check(is_wp_error(VTO\Provider_Result::fetch($url)) && $get_count-$before_loop<=4, 'Redirect loops stop after the configured hop limit');
    foreach(['http://1.1.1.1/photo.png','https://127.0.0.1/private','https://[::1]/private','https://user:password@1.1.1.1/private','file:///etc/passwd','https://1.1.1.1:8443/private'] as $unsafe_url){
        $before=$get_count;$unsafe=VTO\Provider_Result::fetch($unsafe_url);
        result_check(is_wp_error($unsafe) && $get_count===$before, 'Unsafe result URL rejected before any HTTP request: '.parse_url($unsafe_url,PHP_URL_SCHEME));
    }
    $download_mode='image';
    $decoded=VTO\Provider_Result::decode(response_fixture(['data'=>[['b64_json'=>base64_encode($png)]]]),[]);
    result_check(!is_wp_error($decoded) && $decoded['bytes']===$png, 'Legacy b64_json image response remains supported');
    $before_schema_get=$get_count;
    $avalai_url_result=VTO\Provider_Result::decode(response_fixture(['data'=>[['b64_json'=>'','revised_prompt'=>'safe test prompt','url'=>$url]]]),[]);
    result_check(!is_wp_error($avalai_url_result) && $avalai_url_result['bytes']===$result_png && $get_count===$before_schema_get+1, 'AvalAI empty base64 plus URL response downloads the existing result with one GET');
    if(!is_wp_error($avalai_url_result) && !empty($avalai_url_result['recovery_path'])){VTO\Storage::delete($avalai_url_result['recovery_path']);}
    $unknown=VTO\Provider_Result::decode(response_fixture(['secret_token'=>'TOP_SECRET_VALUE','customer_photo'=>'RAW_PHOTO_BYTES','extra'=>['token'=>'NESTED_SECRET']]),[]);
    result_check(is_wp_error($unknown), 'Unknown successful response schema is diagnosed rather than treated as completion');
    $unknown_diagnostic=wp_json_encode([$unknown->get_error_message(),$unknown->get_error_data()]);
    result_check(!str_contains($unknown_diagnostic,'TOP_SECRET_VALUE') && !str_contains($unknown_diagnostic,'RAW_PHOTO_BYTES') && !str_contains($unknown_diagnostic,'NESTED_SECRET'), 'Unknown response diagnostic includes no token or photo field values');
    $html=VTO\Provider_Result::decode(response_fixture('<html>SESSION_SECRET</html>',202,['content-type'=>'text/html']),[]);
    result_check(is_wp_error($html) && !str_contains(wp_json_encode($html->get_error_data()),'SESSION_SECRET'), 'HTML 202 response is safely distinguished from completed image data');
    $malformed=VTO\Provider_Result::decode(response_fixture(['data'=>[['b64_json'=>'invalid!!']]]),[]);
    result_check(is_wp_error($malformed), 'Invalid base64 remains rejected by response decoder');
    $download_mode='transport';
    $delete_target=$create();VTO\Jobs::process($delete_target['id']);
    $delete_row=VTO\Jobs::by_id($delete_target['id']);$delete_ref=$delete_row['reference_path'];
    result_check($delete_row['status']==='failed' && is_file($delete_ref) && VTO\Jobs::delete($delete_target['id'])===true && !file_exists($delete_ref) && !file_exists($delete_row['input_path']), 'Explicit deletion removes retained signed reference and input files');
    result_check(VTO\Jobs::delete($first['id'])===true && !file_exists($row['output_path']) && !file_exists($row['input_path']), 'Explicit deletion also removes a completed request image result');
    echo "\n$passed provider-result assertions passed. All HTTP requests were intercepted; no paid API request occurred.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'FAIL '.$error->getMessage().PHP_EOL);$exit_code=1;
} finally {
    if($filter){remove_filter('pre_http_request',$filter,10);}
    foreach($created_jobs as $id){VTO\Jobs::delete((int)$id);}
    foreach($temporary_paths as $path){if(is_string($path)){VTO\Storage::delete($path);}}
    if($product_id){wp_delete_post($product_id,true);}
    if($attachment_id){wp_delete_attachment($attachment_id,true);}
    if($old_settings===null){delete_option('vto_settings');}else{update_option('vto_settings',$old_settings,false);}
    wp_set_current_user($old_user);
}
exit($exit_code);
