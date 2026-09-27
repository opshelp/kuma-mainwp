<?php
declare(strict_types=1);
require __DIR__ . '/environment.php';

use KumaMainWP\Settings;
use KumaMainWP\Client;
use KumaMainWP\Poller;
use KumaMainWP\Presentation;
use KumaMainWP\Plugin;
use KumaMainWP\Admin;

function configure(): void {
    delete_option('kmw_settings'); delete_option('kmw_state'); delete_option('kmw_lock'); delete_option('kmw_mappings');
    Settings::save(['url'=>'https://kuma.example.test','api_key'=>'test-secret','allow_http'=>false]);
}
function response(string $body, int $code = 200): array {
    return ['headers'=>[], 'body'=>$body, 'response'=>['code'=>$code,'message'=>''], 'cookies'=>[], 'filename'=>null];
}

test('integration executes every runtime class from the installed plugin', function (): void {
    foreach (['Admin','Client','Lock','Management','Matcher','Metrics','Plugin','Poller','Presentation','Provisioner','Settings','Socket'] as $name) {
        $class = new ReflectionClass('KumaMainWP\\'.$name);
        same(realpath(dirname(KMW_FILE).'/includes/'.$name.'.php'),realpath($class->getFileName()));
    }
});

test('configuration encrypts credentials and blank fields preserve the key', function (): void {
    configure();
    same('test-secret', Settings::key());
    truth(!str_contains(serialize(get_option('kmw_settings')), 'test-secret'));
    Settings::save(['url'=>'https://kuma.example.test/metrics','api_key'=>'','allow_http'=>false]);
    same('https://kuma.example.test', Settings::get()['url']);
    same('test-secret', Settings::key());
});
test('unsafe endpoint forms are rejected and local HTTP requires explicit opt-in', function (): void {
    configure();
    foreach (['http://localhost:3001','https://user:pass@example.test','https://example.test/?token=secret','https://example.test/#fragment','file:///tmp/x','https://'] as $url) {
        throws(fn() => Settings::save(['url'=>$url,'api_key'=>'','allow_http'=>false]));
    }
    Settings::save(['url'=>'http://127.0.0.1:8772','api_key'=>'test-secret','allow_http'=>true]);
    same('http://127.0.0.1:8772', Settings::get()['url']);
});
test('changing endpoint clears old data, mappings and inherited credentials', function (): void {
    configure();update_option('kmw_state',['monitors'=>[1=>['name'=>'old']]]);update_option('kmw_mappings',[2=>'1']);
    Settings::save(['url'=>'https://other.example.test','api_key'=>'','allow_http'=>false]);
    same('', Settings::key());same(false,get_option('kmw_state'));same(false,get_option('kmw_mappings'));
});
test('corrupt encrypted keys fail explicitly', function (): void {
    configure();$s=get_option('kmw_settings');$s['key']='corrupt';update_option('kmw_settings',$s);
    throws(fn() => Settings::key());
});
test('transport sends Basic API-key auth and refuses redirects', function (): void {
    configure();
    $hook = function ($pre, $args, $url) {
        same('https://kuma.example.test/metrics',$url);
        same('Basic ' . base64_encode(':test-secret'),$args['headers']['Authorization']);
        same(0,$args['redirection']);same(true,$args['sslverify']);truth($args['limit_response_size'] <= 5242881);
        return response('sensitive upstream body',302);
    };
    add_filter('pre_http_request',$hook,10,3);
    try { throws(fn() => Client::fetch(Settings::get(),Settings::key())); }
    finally { remove_filter('pre_http_request',$hook,10); }
});
test('failed polling preserves good data and immediately marks it stale', function (): void {
    configure();
    $ok=fn()=>response(file_get_contents(__DIR__.'/fixtures/kuma.prom'));
    add_filter('pre_http_request',$ok);
    $good=Poller::refresh();remove_filter('pre_http_request',$ok);
    same(2,count($good['monitors']));same(false,Poller::stale($good));
    $bad=fn()=>response('secret upstream details',401);add_filter('pre_http_request',$bad);
    $failed=Poller::refresh();remove_filter('pre_http_request',$bad);
    same($good['monitors'],$failed['monitors']);same($good['success_at'],$failed['success_at']);same(true,Poller::stale($failed));
    truth(!str_contains($failed['error'],'secret'));truth(str_contains($failed['error'],'401'));
    same(false,get_option('kmw_lock'));
});
test('age alone makes cached status stale', function (): void {
    same(true,Poller::stale(['success_at'=>time()-181,'error'=>'']));
    same(false,Poller::stale(['success_at'=>time()-10,'error'=>'']));
    same(true,Poller::stale(['success_at'=>0,'error'=>'']));
});
test('HTML and oversized metrics cannot overwrite cached data', function (): void {
    configure();update_option('kmw_state',['revision'=>Settings::get()['revision'],'monitors'=>[7=>['id'=>'7']],'success_at'=>time(),'attempt_at'=>time(),'error'=>'']);
    foreach (['<html>Sign in</html>',str_repeat('x',5242881)] as $body) {
        $hook=fn()=>response($body);add_filter('pre_http_request',$hook);
        $state=Poller::refresh();remove_filter('pre_http_request',$hook);
        same([7=>['id'=>'7']],$state['monitors']);same(true,Poller::stale($state));
    }
});
test('configuration changes during fetch discard the old instance response', function (): void {
    configure();
    $hook=function () { Settings::save(['url'=>'https://new.example.test','api_key'=>'new-secret','allow_http'=>false]);return response(file_get_contents(__DIR__.'/fixtures/kuma.prom')); };
    add_filter('pre_http_request',$hook);
    Poller::refresh();remove_filter('pre_http_request',$hook);
    same([],Poller::state()['monitors']);same('https://new.example.test',Settings::get()['url']);
});
test('a separate settings-save request invalidates an in-flight response', function (): void {
    configure();
    $hook=function () {
        $process=proc_open([PHP_BINARY,__DIR__.'/change-config.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        same(0,proc_close($process));
        return response(file_get_contents(__DIR__.'/fixtures/kuma.prom'));
    };
    add_filter('pre_http_request',$hook);Poller::refresh();remove_filter('pre_http_request',$hook);
    // Simulate a new request's option cache after the older worker has finished.
    wp_cache_flush();
    same('https://concurrent.example.test',Settings::get()['url']);
    same([],Poller::state()['monitors']);
});
test('an active collection lock prevents duplicate HTTP calls', function (): void {
    configure();add_option('kmw_lock',['token'=>'other','expires'=>time()+60],'',false);
    $calls=0;$hook=function () use (&$calls) { ++$calls;return response(''); };add_filter('pre_http_request',$hook);
    Poller::refresh();remove_filter('pre_http_request',$hook);
    same(0,$calls);same('other',get_option('kmw_lock')['token']);delete_option('kmw_lock');
});
test('a competing lock inserted just before acquisition is never overwritten', function (): void {
    configure();
    global $wpdb;
    $other=['token'=>'competing-worker','expires'=>time()+60];
    $interleave=function ($query) use ($wpdb,$other) {
        if (str_starts_with($query,'INSERT') && str_contains($query,"'kmw_lock'")) {
            // Bypass the filter to model another connection winning after our initial read.
            mysqli_query($wpdb->dbh, $wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",'kmw_lock',maybe_serialize($other)));
        }
        return $query;
    };
    $calls=0;$http=function () use (&$calls) { ++$calls;return response(''); };
    add_filter('query',$interleave);add_filter('pre_http_request',$http);
    try { Poller::refresh(); }
    finally { remove_filter('query',$interleave);remove_filter('pre_http_request',$http); }
    wp_cache_delete('kmw_lock','options');wp_cache_delete('notoptions','options');
    try { same(0,$calls);same($other,get_option('kmw_lock')); }
    finally { delete_option('kmw_lock'); }
});
test('finishing a poll cannot release a newer workers lock from a stale option cache', function (): void {
    configure();global $wpdb;
    $other=['token'=>'replacement-worker','expires'=>time()+60];
    $http=function () use ($wpdb,$other) {
        get_option('kmw_lock'); // Cache this worker's token before the replacement.
        $wpdb->update($wpdb->options,['option_value'=>maybe_serialize($other)],['option_name'=>'kmw_lock']);
        return response(file_get_contents(__DIR__.'/fixtures/kuma.prom'));
    };
    add_filter('pre_http_request',$http);
    try { Poller::refresh(); }
    finally { remove_filter('pre_http_request',$http); }
    wp_cache_delete('kmw_lock','options');wp_cache_delete('notoptions','options');
    try { same($other,get_option('kmw_lock')); }
    finally { delete_option('kmw_lock'); }
});
test('mapping and connection saves cannot overwrite a running creation batch', function (): void {
    configure();$settings=Settings::get();
    add_option('kmw_provision_lock',['token'=>'active-batch','expires'=>time()+120],'',false);
    try {
        throws(fn()=>Admin::saveMappings([2=>'none'],$settings['revision'],[['id'=>2,'url'=>'https://example.com/']]));
        throws(fn()=>Settings::save(['url'=>$settings['url'],'api_key'=>'new-key']));
        same($settings,Settings::get());same([],Plugin::mappings());
    } finally { delete_option('kmw_provision_lock'); }
});
test('management sign-in cannot send credentials to an endpoint changed after form validation', function (): void {
    configure();wp_set_current_user(1);$revision=Settings::get()['revision'];
    $swap=null;
    $swap=function ($pre) use (&$swap) {
        remove_filter('pre_option_kmw_settings',$swap);
        Settings::save(['url'=>'https://replacement.example.test','api_key'=>'','allow_http'=>false]);
        return false;
    };
    $requests=[];$http=function ($pre,$args,$url) use (&$requests) { $requests[]=$url;return response('',401); };
    add_filter('pre_option_kmw_settings',$swap);add_filter('pre_http_request',$http,10,3);
    try { throws(fn()=>\KumaMainWP\Management::connect('fixture','fixture-password','',$revision)); }
    finally { remove_filter('pre_option_kmw_settings',$swap);remove_filter('pre_http_request',$http,10); }
    same([],$requests);
});
test('report values distinguish zero uptime, unavailable values and stale results', function (): void {
    $state=['monitors'=>\KumaMainWP\Metrics::parse(file_get_contents(__DIR__.'/fixtures/kuma.prom')),'success_at'=>time(),'error'=>''];
    $site=['id'=>2,'url'=>'https://example.com/'];
    $tokens=Presentation::tokens($site,$state,[]);
    same('0.00%', $tokens['[kuma.uptime.30d]']);same('Up',$tokens['[kuma.status]']);same('Unavailable',$tokens['[kuma.uptime.365d]']);
    $state['error']='Cannot reach Kuma';
    $tokens=Presentation::tokens($site,$state,[]);
    truth(str_contains($tokens['[kuma.uptime.30d]'],'stale'));truth(str_contains($tokens['[kuma.status]'],'stale'));
});
test('column escapes monitor content and shows staleness before old status', function (): void {
    configure();$s=['monitors'=>\KumaMainWP\Metrics::parse(file_get_contents(__DIR__.'/fixtures/kuma.prom')),'success_at'=>time(),'error'=>''];
    $s['monitors'][7]['name']='<script>alert(1)</script>';
    $html=Presentation::column(['id'=>2,'url'=>'https://example.com/'],$s,[]);
    truth(!str_contains($html,'<script>'));truth(str_contains($html,'125 ms'));truth(str_contains($html,'99.95%'));
    $s['success_at']=time()-181;$html=Presentation::column(['id'=>2,'url'=>'https://example.com/'],$s,[]);
    truth(str_contains($html,'Stale'));truth(!str_contains($html,'kmw-up'));
});
test('unauthorized users cannot render the monitor inventory', function (): void {
    wp_set_current_user(0);
    $die=fn()=>function () { throw new RuntimeException('access denied'); };
    add_filter('wp_die_handler',$die);
    try { throws(fn() => Admin::render()); } finally { remove_filter('wp_die_handler',$die); }
});
test('settings actions reject invalid nonces without changing the connection', function (): void {
    configure();$original=Settings::get();wp_set_current_user(1);
    $_SERVER['REQUEST_METHOD']='POST';$_REQUEST['_wpnonce']='invalid';
    $_POST=['kmw_op'=>'save','url'=>'https://unexpected.example.test','api_key'=>'bad'];
    $die=fn()=>function () { throw new RuntimeException('nonce denied'); };add_filter('wp_die_handler',$die);
    try { throws(fn()=>Admin::handle());same($original,Settings::get()); }
    finally { remove_filter('wp_die_handler',$die);$_POST=[];$_REQUEST=[]; }
});
test('mapping save rejects arbitrary site IDs and nonexistent monitors', function (): void {
    $sites=[['id'=>2,'url'=>'https://example.com/']];$monitors=[7=>['id'=>'7']];
    same([2=>'7'],Admin::validateMappings([2=>'7'],$sites,$monitors,[]));
    throws(fn()=>Admin::validateMappings([3=>'7'],$sites,$monitors,[]));
    throws(fn()=>Admin::validateMappings([2=>'999'],$sites,$monitors,[]));
    same([2=>'999'],Admin::validateMappings([2=>'999'],$sites,$monitors,[2=>'999']));
});
test('form submissions return to their originating Kuma screen on success and failure', function (): void {
    configure(); wp_set_current_user(1);
    $http=fn()=>response(file_get_contents(__DIR__.'/fixtures/kuma.prom'));
    $redirect=function ($url) { throw new Exception($url); };
    add_filter('pre_http_request',$http);add_filter('wp_redirect',$redirect);
    try {
        foreach (['Extensions-Kuma-Mainwp'=>'admin.php?page=Extensions-Kuma-Mainwp','kuma-mainwp'=>'options-general.php?page=kuma-mainwp','https://outside.example/'=>'options-general.php?page=kuma-mainwp'] as $page=>$path) {
            foreach (['refresh','invalid'] as $op) {
                $_SERVER['REQUEST_METHOD']='POST';$_REQUEST['_wpnonce']=wp_create_nonce('kmw_action');
                $_POST=['kmw_op'=>$op,'kmw_page'=>$page];
                try { Admin::handle();throw new LogicException('Expected redirect'); }
                catch (Exception $e) { same(admin_url($path),$e->getMessage()); }
            }
        }
    } finally { remove_filter('pre_http_request',$http);remove_filter('wp_redirect',$redirect);$_POST=[];$_REQUEST=[]; }
});
test('all forms carry the current extension page without accepting arbitrary return URLs', function (): void {
    foreach (['Extensions-Kuma-Mainwp','kuma-mainwp'] as $page) {
        $_GET['page']=$page;
        foreach (['save','refresh','mappings'] as $op) {
            ob_start();Admin::formStart($op);$html=ob_get_clean();
            truth(str_contains($html,'name="kmw_page" value="'.$page.'"'));
        }
    }
    $_GET=[];
});
test('stale mapping forms cannot target another instance with reused monitor IDs', function (): void {
    configure();$old=Settings::get()['revision'];
    Settings::save(['url'=>'https://different.example.test','api_key'=>'different','allow_http'=>false]);
    update_option('kmw_state',['revision'=>Settings::get()['revision'],'monitors'=>[7=>['id'=>'7']],'success_at'=>time(),'error'=>'']);
    throws(fn()=>Admin::saveMappings([2=>'7'],$old,[['id'=>2,'url'=>'https://example.com/']]));
    same([],Plugin::mappings());
});
test('saving the same endpoint preserves manual mappings with the new revision', function (): void {
    configure();$revision=Settings::get()['revision'];
    update_option('kmw_mappings',['revision'=>$revision,'sites'=>[2=>'7']]);
    Settings::save(['url'=>'https://kuma.example.test','api_key'=>'','allow_http'=>false]);
    same([2=>'7'],Plugin::mappings());
});
test('authorized MainWP team members see only permitted site columns', function (): void {
    $rights=function ($allowed,$type,$cap) { return !empty($GLOBALS['kmw_test_rights'][$type][(string)$cap]); };
    add_filter('mainwp_currentusercan',$rights,10,3);
    $user=get_user_by('login','kmw_subscriber');
    $id=$user ? $user->ID : wp_insert_user(['user_login'=>'kmw_subscriber','user_pass'=>wp_generate_password(24),'role'=>'subscriber']);
    wp_set_current_user((int)$id);
    $GLOBALS['kmw_test_rights']=['dashboard'=>['access_global_dashboard'=>true],'site'=>['2'=>true]];
    truth(array_key_exists('kmw_status',Plugin::columns([])));
    truth(array_key_exists('kmw_status',Plugin::column(['id'=>2,'url'=>'https://example.com/'],'kmw_status')));
    same('',Plugin::column(['id'=>3,'url'=>'https://example.com/'],'kmw_status')['kmw_status'] ?? 'missing');
    $GLOBALS['kmw_test_rights']=[];
    truth(!array_key_exists('kmw_status',Plugin::columns([])));
    remove_filter('mainwp_currentusercan',$rights,10);
    wp_set_current_user(1);
});
run_tests();
