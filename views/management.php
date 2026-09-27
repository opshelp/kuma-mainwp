<?php
if (!defined('ABSPATH')) { exit; }
use KumaMainWP\{Admin,Management,Provisioner};
$management = Management::get(); $inventory = Management::snapshot(); $pending = Provisioner::pending();
?>
<section class="kmw-card">
    <h2>Add MainWP sites to Kuma</h2>
    <p>Create monitors for selected sites that Kuma is not monitoring yet. Existing monitors keep their settings, including paused status.</p>
    <?php if (!$management): ?>
        <p>Sign in to Kuma to load its full monitor list and add monitors. The metrics API key only reads monitoring data.</p>
    <?php else: ?>
        <p><strong>Management connected.</strong> Full list last checked: <?php echo $inventory['checked_at'] ? esc_html(human_time_diff($inventory['checked_at']) . ' ago') : 'not yet'; ?>. The list is checked again before adding each monitor.</p>
        <div class="kmw-refresh"><?php Admin::formStart('management_refresh'); ?><button type="submit" class="button">Refresh available sites</button></form><?php Admin::formStart('management_disconnect'); ?><button type="submit" class="button">Disconnect management</button></form></div>
        <?php
        $candidates = Provisioner::candidates($sites, $inventory['monitors'], $mappings, $pending);
        $visible = array_filter($candidates, static fn(array $c): bool => !$c['monitor'] || isset($pending[$c['site']['id']]));
        $selectable = array_filter($visible, static fn(array $c): bool => $c['eligible'] || $c['monitor']);
        $reasons = ['related'=>'A monitor uses a related URL. Review and link it above.','ambiguous'=>'Multiple monitors match. Choose one above.','disabled'=>'Disabled for this site.','missing'=>'Previously linked monitor is missing. Review the mapping above.','invalid'=>'This site needs a valid HTTP(S) URL.','pending'=>'An earlier add was not confirmed. Check Kuma, then refresh this list.'];
        ?>
        <?php if (!$visible): ?><p class="kmw-empty">All accessible MainWP sites already have a matching Kuma monitor.</p>
        <?php else: Admin::formStart('create_monitors'); ?>
            <div class="kmw-table-scroll"><table class="widefat striped"><thead><tr><th scope="col">Select</th><th scope="col">MainWP site</th><th scope="col">Action</th></tr></thead><tbody>
            <?php foreach ($visible as $id=>$candidate): $site = $candidate['site']; $canSelect = $candidate['eligible'] || $candidate['monitor']; ?>
                <tr><td><?php if ($canSelect): ?><input type="checkbox" name="site_ids[]" value="<?php echo esc_attr((string) $id); ?>" aria-label="<?php echo esc_attr('Add or link ' . ($site['name'] ?? $site['url'])); ?>"><?php else: ?>—<?php endif; ?></td>
                    <td><strong><?php echo esc_html($site['name'] ?? $site['url']); ?></strong><br><small><?php echo esc_html($site['url']); ?></small></td>
                    <td><?php echo esc_html($candidate['eligible'] ? 'Create HTTP monitor' : ($candidate['monitor'] ? 'Found in Kuma — link existing monitor' : ($reasons[$candidate['reason']] ?? 'Review in Kuma.'))); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php if ($selectable): ?>
                <div class="kmw-field"><label for="kmw-interval">Check interval (seconds)</label><input id="kmw-interval" type="number" name="interval" min="20" max="86400" value="60" required><p class="description">New monitors use HTTP GET, accept 200–299 responses, verify TLS certificates and retry twice before reporting down.</p></div>
                <fieldset class="kmw-field"><legend><strong>Notifications for new monitors</strong></legend>
                <?php $activeNotifications = array_filter($inventory['notifications'], static fn(array $n): bool => $n['active']); ?>
                <?php if (!$activeNotifications): ?><p>No active notification methods in Kuma. These monitors will not send alerts until you configure notifications in Kuma.</p>
                <?php else: foreach ($activeNotifications as $notification): ?>
                    <label class="kmw-checkbox"><input type="checkbox" name="notifications[]" value="<?php echo esc_attr($notification['id']); ?>" <?php checked($notification['default']); ?>> <?php echo esc_html($notification['name']); ?><?php echo $notification['default'] ? ' (Kuma default)' : ''; ?></label>
                <?php endforeach; ?><p class="description">Kuma defaults are selected. Leave all unchecked to create without notifications.</p><?php endif; ?>
                </fieldset>
                <p><button type="submit" class="button button-primary">Add selected monitors</button> <span class="description">Select up to 10 sites at a time.</span></p>
            <?php endif; ?>
        </form><?php endif; ?>
        <?php $retryable = array_filter($candidates, static fn(array $c): bool => $c['reason'] === 'pending' && isset($pending[$c['site']['id']])); ?>
        <?php if ($retryable): ?>
            <details><summary>Resolve unconfirmed adds</summary>
                <p>First check directly in Kuma and wait for any in-progress add to finish. Select only sites you have confirmed were not created. We will check the full list again before allowing a retry.</p>
                <?php Admin::formStart('management_retry'); ?>
                <?php foreach ($retryable as $id=>$candidate): ?><p><label><input type="checkbox" name="site_ids[]" value="<?php echo esc_attr((string) $id); ?>"> I checked Kuma: <?php echo esc_html($candidate['site']['name'] ?? $candidate['site']['url']); ?> was not created.</label></p><?php endforeach; ?>
                <button type="submit" class="button">Allow retry for checked sites</button></form>
            </details>
        <?php endif; ?>
    <?php endif; ?>
    <details <?php echo !$management ? 'open' : ''; ?>><summary><?php echo $management ? 'Sign in again' : 'Connect Kuma management'; ?></summary>
        <?php Admin::formStart('management_connect'); ?>
            <div class="kmw-field"><label for="kmw-username">Kuma username</label><input type="text" id="kmw-username" name="username" autocomplete="off" maxlength="250" required></div>
            <div class="kmw-field"><label for="kmw-password">Kuma password</label><input type="password" id="kmw-password" name="password" autocomplete="new-password" maxlength="4096" required></div>
            <div class="kmw-field"><label for="kmw-two-factor">Two-factor code (if enabled)</label><input type="text" id="kmw-two-factor" name="two_factor" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"></div>
            <p class="description">Connects to your saved Kuma URL. Only the returned session token is stored, encrypted; your password and two-factor code are not saved.</p>
            <button type="submit" class="button" <?php disabled($settings['url'] === ''); ?>>Connect management</button>
        </form>
    </details>
</section>
