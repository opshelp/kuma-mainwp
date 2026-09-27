<?php
/** @var array $settings @var array $state @var array $sites @var array $mappings */
if (!defined('ABSPATH')) { exit; }
use KumaMainWP\Admin;
use KumaMainWP\Matcher;
use KumaMainWP\Plugin;
use KumaMainWP\Poller;
use KumaMainWP\Presentation;
$stale = Poller::stale($state);
?>
<div class="wrap kmw">
    <div class="kmw-heading"><div><p class="kmw-eyebrow">MAINWP ADD-ON</p><h1>Kuma Monitor</h1><p>Your private monitoring, alongside your sites.</p></div><span class="kmw-version">v<?php echo esc_html(KMW_VERSION); ?></span></div>
    <?php if (is_array($flash)): ?><div class="notice <?php echo $flash['error'] ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html($flash['message']); ?></p></div><?php endif; ?>
    <?php if (!Plugin::ready()): ?><div class="notice notice-warning"><p>Activate MainWP Dashboard to match monitors to sites. Install this add-on on the Dashboard site only.</p></div><?php endif; ?>
    <div class="kmw-summary">
        <div><span>MONITORS</span><strong><?php echo esc_html((string) count($state['monitors'])); ?></strong></div>
        <div><span>CONNECTION</span><strong><?php echo esc_html(!$state['success_at'] ? 'Not connected' : ($stale ? 'Stale data' : 'Connected')); ?></strong></div>
        <div><span>LAST COLLECTED</span><strong><?php echo $state['success_at'] ? esc_html(human_time_diff($state['success_at']) . ' ago') : 'Never'; ?></strong></div>
    </div>
    <?php if ($state['error']): ?><div class="notice notice-error"><p><?php echo esc_html($state['error']); ?> Last collected data is retained below and marked stale.</p></div><?php elseif ($stale && $state['success_at']): ?><div class="notice notice-warning"><p>Data is over three minutes old. Refresh now and check that WP-Cron is running.</p></div><?php endif; ?>
    <section class="kmw-card">
        <h2>Connect Uptime Kuma</h2>
        <p>Use a metrics API key from Kuma’s <strong>Settings → API Keys</strong>. No public status page is needed.</p>
        <?php Admin::formStart('save'); ?>
            <div class="kmw-field"><label for="kmw-url">Kuma base URL</label><input type="url" id="kmw-url" name="url" value="<?php echo esc_attr($settings['url']); ?>" placeholder="https://kuma.example.com" required maxlength="2048"><p class="description">Include the port or subdirectory if used. We append <code>/metrics</code>.</p></div>
            <div class="kmw-field"><label for="kmw-key">Metrics API key</label>
                <?php if (defined('KMW_API_KEY')): ?><p>Supplied through <code>KMW_API_KEY</code> in wp-config.php.</p><input type="hidden" name="api_key" value="">
                <?php else: ?><input type="password" id="kmw-key" name="api_key" value="" autocomplete="new-password" maxlength="4096" placeholder="<?php echo $settings['key'] ? 'Saved — leave blank to keep' : 'Paste an API key'; ?>"><p class="description">Encrypted in the database. Changing the base URL requires entering the key again.</p><?php endif; ?>
            </div>
            <div class="kmw-field"><label class="kmw-checkbox"><input type="checkbox" name="allow_http" value="1" <?php checked($settings['allow_http']); ?>> Allow HTTP on a trusted private network</label><p class="description">HTTP sends the key without transport encryption. HTTPS certificate verification stays enabled.</p></div>
            <button class="button button-primary" type="submit">Save &amp; test connection</button>
        </form>
        <div class="kmw-refresh"><?php Admin::formStart('refresh'); ?><button class="button" type="submit">Refresh metrics now</button></form><span>Refreshes every minute via WP-Cron. A quiet dashboard may need a server cron job.</span></div>
    </section>
    <section class="kmw-card">
        <h2>Match monitors to sites</h2>
        <p>Unique, exact URL matches are automatic. Choose a monitor when a site has multiple checks or a different monitoring URL.</p>
        <?php if (!$sites): ?><p class="kmw-empty">No accessible MainWP sites yet. Connect a site in MainWP, then return here.</p>
        <?php else: Admin::formStart('mappings'); ?>
            <div class="kmw-table-scroll"><table class="widefat striped"><thead><tr><th scope="col">MainWP site</th><th scope="col">Kuma monitor</th><th scope="col">Status</th></tr></thead><tbody>
            <?php foreach ($sites as $site): $selected = $mappings[$site['id']] ?? ''; $match = Matcher::find($site, $state['monitors'], $mappings); ?>
                <tr><td><strong><?php echo esc_html($site['name'] ?? $site['url']); ?></strong><br><small><?php echo esc_html($site['url']); ?></small></td><td>
                    <label class="screen-reader-text" for="kmw-site-<?php echo esc_attr((string) $site['id']); ?>">Monitor for <?php echo esc_html($site['name'] ?? $site['url']); ?></label>
                    <select id="kmw-site-<?php echo esc_attr((string) $site['id']); ?>" name="mappings[<?php echo esc_attr((string) $site['id']); ?>]">
                        <option value="" <?php selected($selected, ''); ?>>Automatic URL match</option><option value="none" <?php selected($selected, 'none'); ?>>Disabled for this site</option>
                        <?php if ($selected !== '' && $selected !== 'none' && !isset($mappingMonitors[$selected])): ?><option value="<?php echo esc_attr($selected); ?>" selected>Missing monitor #<?php echo esc_html($selected); ?></option><?php endif; ?>
                        <?php foreach ($mappingMonitors as $monitor): ?><option value="<?php echo esc_attr($monitor['id']); ?>" <?php selected($selected, $monitor['id']); ?>><?php echo esc_html($monitor['name'] . ' (#' . $monitor['id'] . ')' . (isset($monitor['active']) && !$monitor['active'] ? ' — paused' : '')); ?></option><?php endforeach; ?>
                    </select>
                    <?php if ($selected === '' && $match['monitor']): ?><br><small>Matched: <?php echo esc_html($match['monitor']['name']); ?></small><?php endif; ?>
                </td><td><?php echo Presentation::column($site, $state, $mappings); // Escaped by Presentation. ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div><p><button class="button button-primary" type="submit">Save site mappings</button></p>
        </form><?php endif; ?>
    </section>
    <section class="kmw-card">
        <h2>Monitor inventory <?php if ($stale && $state['monitors']): ?><span class="kmw-badge kmw-stale">Stale</span><?php endif; ?></h2>
        <?php if (!$state['monitors']): ?><p class="kmw-empty">Connect Kuma to load your monitors. Missing or paused monitors are never assumed to be up.</p>
        <?php else: ?><div class="kmw-table-scroll"><table class="widefat striped"><thead><tr><th scope="col">Monitor</th><th scope="col"><?php echo $stale ? 'Last status' : 'Status'; ?></th><th scope="col">Response</th><th scope="col">Uptime · 24h</th><th scope="col">Uptime · 30d</th><th scope="col">Uptime · 365d</th><th scope="col">Certificate</th></tr></thead><tbody>
            <?php foreach ($state['monitors'] as $m): ?><tr>
                <td><a href="<?php echo esc_url($settings['url'] . '/dashboard/' . rawurlencode($m['id'])); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($m['name']); ?></a><br><small>#<?php echo esc_html($m['id']); ?> · <?php echo esc_html($m['type']); ?></small></td>
                <td><span class="kmw-badge kmw-<?php echo esc_attr($stale ? 'stale' : strtolower(Presentation::status($m['status']))); ?>"><?php echo esc_html(Presentation::status($m['status'])); ?></span></td>
                <td><?php echo esc_html($m['response_ms'] === null ? '—' : number_format($m['response_ms'], 0) . ' ms'); ?></td>
                <?php foreach (['1d','30d','365d'] as $window): ?><td><?php echo esc_html(Presentation::percent($m['uptime'][$window] ?? null)); ?></td><?php endforeach; ?>
                <td><?php echo esc_html($m['cert_days'] === null ? '—' : number_format($m['cert_days'], 0) . ' days'); ?></td>
            </tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <p class="description">Values describe the most recent metrics collection. Availability depends on monitor type and Kuma version.</p>
    </section>
    <?php require __DIR__ . '/management.php'; ?>
    <section class="kmw-card">
        <h2>Use Kuma in Pro Reports</h2><p>Create custom tokens with the names below in Pro Reports, then insert them into your report template. Values are filled when the report is generated.</p>
        <div class="kmw-report-note"><strong>Rolling windows, collected now.</strong> Uptime figures do not follow the report’s date range. Label them “last 24 hours”, “last 30 days” or “last 365 days”; they are not calendar-month uptime. Stale data is reported as unavailable.</div>
        <dl class="kmw-tokens"><?php foreach (Presentation::TOKENS as $token=>$description): ?><dt><code><?php echo esc_html($token); ?></code></dt><dd><?php echo esc_html($description); ?></dd><?php endforeach; ?></dl>
    </section>
    <p class="kmw-footer">Kuma owns monitoring and notifications. This independent add-on reads metrics and can create monitors you select. Existing monitor settings stay in Kuma.</p>
</div>
