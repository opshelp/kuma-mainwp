<?php
declare(strict_types=1);
require __DIR__ . '/environment.php';
use KumaMainWP\Settings;
use KumaMainWP\Poller;
use KumaMainWP\Plugin;
use KumaMainWP\Admin;
wp_set_current_user(1);

test('MainWP recognizes the add-on and registers its site column',function (): void {
    truth(Plugin::ready());
    $registered=apply_filters('mainwp_getextensions',[]);
    truth(count(array_filter($registered,fn($e)=>$e['plugin']===KMW_FILE))===1);
    truth(array_key_exists('kmw_status',apply_filters('mainwp_sitestable_getcolumns',[])));
});
test('real HTTP metrics collection authenticates and maps MainWP sites',function (): void {
    Settings::save(['url'=>'http://127.0.0.1:8772','api_key'=>'fixture-secret','allow_http'=>true]);
    $state=Poller::refresh();same('',$state['error']);same(3,count($state['monitors']));
    global $wpdb;
    foreach ([['Northstar Studio','https://northstar.example/'],['Harbor Shop','https://harbor.example/'],['Fieldwork Journal','https://fieldwork.example/']] as [$name,$url]) {
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE url=%s",$url));
        if (!$exists) { truth($wpdb->insert($wpdb->prefix.'mainwp_wp',['userid'=>1,'name'=>$name,'url'=>$url])!==false,$wpdb->last_error); }
        $id=$exists ?: $wpdb->insert_id;
        if (!$wpdb->get_var($wpdb->prepare("SELECT sync_id FROM {$wpdb->prefix}mainwp_wp_sync WHERE wpid=%d",$id))) {
            truth($wpdb->insert($wpdb->prefix.'mainwp_wp_sync',['wpid'=>$id])!==false,$wpdb->last_error);
        }
    }
    $sites=Plugin::sites();truth(count($sites)>=3,'Fixture sites must be returned by MainWP');
    $site=current(array_filter($sites,fn($site)=>$site['url']==='https://northstar.example/'));
    $row=apply_filters('mainwp_sitestable_item',$site,'kmw_status');truth(str_contains($row['kmw_status'],'124 ms'));
    $tokens=apply_filters('mainwp_pro_reports_custom_tokens',['[kuma.status]'=>'','[kuma.uptime.30d]'=>''],null,(object)$site);
    same('Up',$tokens['[kuma.status]']);same('99.98%',$tokens['[kuma.uptime.30d]']);
});
test('actual HTTP redirect is rejected without replacing cached monitors',function (): void {
    $settings=Settings::get();$settings['url']='http://127.0.0.1:8772/redirect';
    throws(fn()=>\KumaMainWP\Client::fetch($settings,'fixture-secret'));
    same(3,count(Poller::state()['monitors']));
});
test('administrator screen renders without exposing stored credentials',function (): void {
    ob_start();Admin::render();$html=ob_get_clean();
    truth(str_contains($html,'Northstar Studio'));truth(str_contains($html,'connection_revision'));
    truth(!str_contains($html,'fixture-secret'));
});
test('cron lifecycle schedules one event and deactivation removes it',function (): void {
    Poller::schedule();Poller::schedule();truth(wp_next_scheduled('kmw_refresh')!==false);
    Poller::deactivate();same(false,wp_next_scheduled('kmw_refresh'));Poller::schedule();
});
run_tests();
