<?php
declare(strict_types=1);
require __DIR__ . '/environment.php';
use KumaMainWP\{Settings,Socket,Management,Provisioner,Plugin};
wp_set_current_user(1);

function local_kuma(): void {
    Settings::save(['url'=>'http://127.0.0.1:8878','api_key'=>'','allow_http'=>true]);
}
test('management login stores an encrypted token and only safe inventory fields', function (): void {
    local_kuma();
    Management::connect('kmw_test','Kuma-local-provisioning-2026!','',Settings::get()['revision']);
    $stored=get_option('kmw_management');
    truth(!str_contains(serialize($stored),'Kuma-local-provisioning-2026!'));
    truth(!str_contains(serialize($stored),'eyJ'));
    $socket=Management::open();
    try { $inventory=$socket->inventory();truth(isset($inventory['monitors'],$inventory['notifications'])); }
    finally { $socket->close(); }
    foreach (Management::snapshot()['monitors'] as $monitor) {
        same(['id','name','url','type','active'],array_keys($monitor));
    }
});
test('incorrect credentials do not replace the working connection', function (): void {
    $before=get_option('kmw_management');
    throws(fn()=>Management::connect('kmw_test','incorrect','',Settings::get()['revision']));
    same($before,get_option('kmw_management'));
});
test('management transport refuses redirects and malformed handshakes', function (): void {
    foreach ([[302,'secret'],[200,'<html>login</html>'],[200,'0{"sid":"../unsafe"}']] as [$code,$body]) {
        $hook=function ($pre,$args) use ($code,$body) {
            same(0,$args['redirection']);same(true,$args['sslverify']);
            return ['headers'=>[],'body'=>$body,'response'=>['code'=>$code,'message'=>''],'cookies'=>[]];
        };
        add_filter('pre_http_request',$hook,10,2);
        try { throws(fn()=>new Socket(Settings::get())); }
        finally { remove_filter('pre_http_request',$hook,10); }
    }
});
test('changing Kuma endpoint removes the old management session and inventory', function (): void {
    Settings::save(['url'=>'https://different.example.test','api_key'=>'','allow_http'=>false]);
    same(false,get_option('kmw_management'));same([],Management::snapshot()['monitors']);
    local_kuma();Management::connect('kmw_test','Kuma-local-provisioning-2026!','',Settings::get()['revision']);
});
test('creation candidates exclude paused matches, related URLs, ambiguity and opt-outs', function (): void {
    $sites=[['id'=>1,'url'=>'https://paused.example/'],['id'=>2,'url'=>'https://new.example/'],['id'=>3,'url'=>'https://www.related.example/'],['id'=>4,'url'=>'https://duplicate.example/'],['id'=>5,'url'=>'https://disabled.example/'],['id'=>6,'url'=>'https://missing.example/'],['id'=>7,'url'=>'javascript:alert(1)']];
    $monitors=[10=>['id'=>'10','url'=>'https://paused.example/','active'=>false],11=>['id'=>'11','url'=>'http://related.example/health'],12=>['id'=>'12','url'=>'https://duplicate.example/'],13=>['id'=>'13','url'=>'https://duplicate.example/']];
    $result=Provisioner::candidates($sites,$monitors,[5=>'none',6=>'999']);
    same([2],array_keys(array_filter($result,fn($r)=>$r['eligible'])));
    same('automatic',$result[1]['reason']);same('related',$result[3]['reason']);same('ambiguous',$result[4]['reason']);same('disabled',$result[5]['reason']);same('missing',$result[6]['reason']);same('invalid',$result[7]['reason']);
});
test('unacknowledged creates block another attempt for the same site or URL', function (): void {
    $sites=[['id'=>1,'url'=>'https://pending.example/'],['id'=>2,'url'=>'https://pending.example/']];
    $result=Provisioner::candidates($sites,[],[],[1=>'https://pending.example/']);
    same([],array_filter($result,fn($r)=>$r['eligible']));same('pending',$result[2]['reason']);
    $result=Provisioner::candidates($sites,[4=>['id'=>'4','url'=>'https://pending.example/']],[],[1=>'https://pending.example/']);
    same('automatic',$result[1]['reason']);
});
test('creation rejects stale forms, arbitrary site IDs and non-administrators before writes', function (): void {
    $revision=Settings::get()['revision'];$management=Management::get()['id'];
    $sites=[['id'=>1,'name'=>'New','url'=>'https://new.example/']];
    throws(fn()=>Provisioner::create(['1'],60,[],'stale',$management,$sites));
    throws(fn()=>Provisioner::create(['1'],60,[],$revision,'stale',$sites));
    throws(fn()=>Provisioner::create(['999'],60,[],$revision,$management,$sites));
    wp_set_current_user(0);$die=fn()=>function () { throw new RuntimeException('denied'); };add_filter('wp_die_handler',$die);
    try { throws(fn()=>Provisioner::create(['1'],60,[],$revision,$management,$sites)); }
    finally { remove_filter('wp_die_handler',$die);wp_set_current_user(1); }
});
test('real Kuma creation is repeatable and preserves paused existing monitor settings', function (): void {
    $pausedUrl='https://paused-'.bin2hex(random_bytes(5)).'.example/';
    $socket=Management::open();
    try {
        $existing=$socket->call('add', [['name'=>'Existing paused','type'=>'http','url'=>$pausedUrl,'active'=>false,'interval'=>300,'retryInterval'=>300,'maxretries'=>0,'accepted_statuscodes'=>['200-299'],'notificationIDList'=>(object)[],'conditions'=>[]]]);
        $existingId=(string)$existing['monitorID'];
    } finally { $socket->close(); }
    $url='https://created-'.bin2hex(random_bytes(5)).'.example/';
    $sites=[['id'=>10001,'name'=>'Created fixture','url'=>$url],['id'=>10002,'name'=>'Paused fixture','url'=>$pausedUrl]];
    $revision=Settings::get()['revision'];$management=Management::get()['id'];
    $result=Provisioner::create(['10001','10002'],60,[],$revision,$management,$sites);
    same(1,$result['created']);same(1,$result['linked']);
    $newId=Plugin::mappings()[10001];same($existingId,Plugin::mappings()[10002]);
    \KumaMainWP\Admin::saveMappings([10005=>$existingId],$revision,[['id'=>10005,'url'=>'https://other-paused-link.example/']]);
    same($existingId,Plugin::mappings()[10005]);
    $repeat=Provisioner::create(['10001','10002'],60,[],$revision,$management,$sites);
    same(0,$repeat['created']);same($newId,Plugin::mappings()[10001]);
    $socket=Management::open();
    try {
        $old=$socket->call('getMonitor',[(int)$existingId]);
        same(300,$old['monitor']['interval']);same(false,(bool)$old['monitor']['active']);
        $new=$socket->call('getMonitor',[(int)$newId]);
        same($url,$new['monitor']['url']);same(60,$new['monitor']['interval']);same(2,$new['monitor']['maxretries']);same(false,(bool)$new['monitor']['ignoreTls']);
        $socket->call('deleteMonitor',[(int)$newId]);$socket->call('deleteMonitor',[(int)$existingId]);
    } finally { $socket->close(); }
});
test('a lost create acknowledgement is recovered by linking the actual monitor without duplication', function (): void {
    $url='https://lost-ack-'.bin2hex(random_bytes(5)).'.example/';
    $sites=[['id'=>10003,'name'=>'Lost acknowledgement fixture','url'=>$url]];
    $revision=Settings::get()['revision'];$management=Management::get()['id'];
    $drop=function ($response) {
        if (str_contains(wp_remote_retrieve_body($response),'"monitorID":')) { return new WP_Error('test_lost_ack','Simulated lost response'); }
        return $response;
    };
    add_filter('http_response',$drop);
    try { $result=Provisioner::create(['10003'],60,[],$revision,$management,$sites); }
    finally { remove_filter('http_response',$drop); }
    same(0,$result['created']);truth($result['error'] !== '');same($url,Provisioner::pending()[10003]);
    $result=Provisioner::create(['10003'],60,[],$revision,$management,$sites);
    same(0,$result['created']);same(1,$result['linked']);truth(!isset(Provisioner::pending()[10003]));
    $socket=Management::open();
    try {
        $matches=array_filter($socket->inventory()['monitors'],fn($m)=>$m['url']===$url);same(1,count($matches));
        $socket->call('deleteMonitor',[(int)array_key_first($matches)]);
    } finally { $socket->close(); }
});
test('a failed send remains pending and active batch locks prevent another create', function (): void {
    $url='https://unsent-'.bin2hex(random_bytes(5)).'.example/';
    $sites=[['id'=>10004,'name'=>'Unsent fixture','url'=>$url]];
    $revision=Settings::get()['revision'];$management=Management::get()['id'];
    $deny=function ($pre,$args) {
        if (preg_match('/^42[0-9]+\["add",/',(string)($args['body'] ?? ''))) { return new WP_Error('test_send_failure','Simulated failure'); }
        return $pre;
    };
    add_filter('pre_http_request',$deny,10,2);
    try { $result=Provisioner::create(['10004'],60,[],$revision,$management,$sites); }
    finally { remove_filter('pre_http_request',$deny,10); }
    truth($result['error'] !== '');same($url,Provisioner::pending()[10004]);
    $result=Provisioner::create(['10004'],60,[],$revision,$management,$sites);
    same(0,$result['created']);same(1,$result['skipped']);
    add_option('kmw_provision_lock',['token'=>'another','expires'=>time()+120],'',false);
    try { throws(fn()=>Provisioner::create(['10004'],60,[],$revision,$management,$sites)); }
    finally { delete_option('kmw_provision_lock'); }
    Provisioner::allowRetry(['10004'],$revision,$management,$sites);
    truth(!isset(Provisioner::pending()[10004]));
    $result=Provisioner::create(['10004'],60,[],$revision,$management,$sites);
    same(1,$result['created']);
    $socket=Management::open();try { $socket->call('deleteMonitor',[(int)Plugin::mappings()[10004]]); } finally { $socket->close(); }
});
test('failure to persist the pending journal prevents a remote create', function (): void {
    $url='https://journal-'.bin2hex(random_bytes(5)).'.example/';
    $sites=[['id'=>10006,'name'=>'Journal fixture','url'=>$url]];
    $deny=fn($value,$old)=>$old;add_filter('pre_update_option_kmw_pending',$deny,10,2);
    try { $result=Provisioner::create(['10006'],60,[],Settings::get()['revision'],Management::get()['id'],$sites); }
    finally { remove_filter('pre_update_option_kmw_pending',$deny,10); }
    same(0,$result['created']);truth($result['error'] !== '');
    $socket=Management::open();
    try { same([],array_filter($socket->inventory()['monitors'],fn($m)=>$m['url']===$url)); }
    finally { $socket->close(); }
});
test('management forms and inventories never render authentication or notification secrets', function (): void {
    ob_start();\KumaMainWP\Admin::render();$html=ob_get_clean();
    truth(str_contains($html,'Add MainWP sites to Kuma'));
    truth(!str_contains($html,'Kuma-local-provisioning-2026!'));truth(!str_contains($html,Management::get()['token']));
    Management::disconnect();same([],Management::get());same([],Management::snapshot()['monitors']);
});
run_tests();
