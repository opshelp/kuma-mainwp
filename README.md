# Kuma Monitor for MainWP

A small, independent WordPress add-on that brings private Uptime Kuma monitors into MainWP. Install it on your **MainWP Dashboard site**, not on child sites.

## Install

1. Download the `kuma-mainwp-0.2.1.zip` release asset and upload it through WordPress **Plugins → Add Plugin → Upload Plugin**, then activate it. When building from source, `php scripts/package.php` creates that file in `dist/`.
2. Open **Settings → Kuma Monitor**, or the Kuma entry under **MainWP → Add-ons**.
3. In Uptime Kuma, create a key under **Settings → API Keys**. These are metrics keys, not push-monitor tokens. Creating your first Kuma API key disables username/password authentication on its metrics endpoint; update existing metrics consumers if necessary.
4. Enter your Kuma base URL and API key, then select **Save & test connection**.
5. Review automatic site matches. Select a specific monitor when URLs differ or multiple checks share a URL. Choose **Disabled for this site** to opt out.
6. Open MainWP **Sites** in table view. If the **Kuma** column is hidden by saved preferences, enable it in **Page Settings → Show columns**.

Requires PHP 8.1+, WordPress 6.5+ and MainWP Dashboard 5.0+. Target metrics format: Uptime Kuma 2.5.5. Compatible versions must expose `monitor_id` labels. Pro Reports 5.1.4+ is recommended for custom-token support. Use a normal per-site activation on the Dashboard; multisite network activation is not supported.

## What it displays

- Up, down, pending, maintenance or unknown status.
- Latest response time and rolling 24-hour uptime in MainWP Sites.
- An administrator-only inventory with rolling 24-hour/30-day/365-day uptime and certificate days remaining, when supplied by Kuma.
- Links to the corresponding monitor in Kuma.
- Collection age and explicit stale/error states. A failed fetch retains old data without presenting it as fresh.

URL matching keeps schemes, paths, queries and ports distinct. Hostnames are case-insensitive and default ports/root slashes are normalized. It does not guess between HTTP/HTTPS, www/non-www or duplicate URLs. Paused/deleted monitors absent from metrics show as unmatched or missing, never as up. A successful collection only confirms data was fetched; monitor heartbeat frequency remains controlled by Kuma.

## Add missing sites to Kuma

Under **Add MainWP sites to Kuma**, connect management using your Kuma username/password and a current two-factor code if enabled. Only the returned session token is stored, encrypted. This optional connection is separate from the read-only metrics API key.

Select up to ten missing sites, choose a check interval and notifications, then click **Add selected monitors**. New monitors use HTTP GET, 200–299 success responses, TLS verification, two retries, and a 60-second default interval. Kuma's default notification methods are preselected; clear them to create without alerts. Existing monitors retain their settings and paused state.

The full authenticated monitor list is rechecked before each add, including paused monitors that are absent from metrics. Exact matches are linked. Related URLs (HTTP/HTTPS, www, paths), multiple matches, disabled sites and missing manual links require review. Use the mapping dropdown to link the intended existing monitor; paused monitors are available after connecting management. Creation never edits, resumes or deletes existing monitors.

If a creation response is lost, the site stays pending. Refresh the full list; if Kuma created it, select it to link the existing monitor. If it was not created, check directly in Kuma and wait for outstanding requests to finish, then use **Resolve unconfirmed adds** to explicitly allow another attempt. The plugin never blindly retries a creation request. A local lock prevents simultaneous batches from this dashboard; Kuma does not enforce URL uniqueness against simultaneous changes from other clients.

Management uses Kuma's internal Socket.IO interface over HTTP polling, tested with **Kuma 2.5.5**. It requires no additional bridge or service. Kuma does not guarantee this interface for third-party integrations, so a future version may need compatibility updates. Reverse proxies must pass `/socket.io/` requests. Disconnecting management removes the local token and inventory while metrics keep working. A changed Kuma password or WordPress salts requires signing in again.

## Refresh and reliability

One request fetches all metrics every 60 seconds through WP-Cron. Screens and reports use the shared cache; rendering a table never makes one HTTP request per site. Data is stale immediately after a failed fetch, or after 180 seconds without a successful collection. Locking prevents overlapping polls; snapshots and mappings are tied to their connection revision.

WP-Cron depends on traffic. For a dashboard visited infrequently, configure your hosting scheduler to run WordPress due cron events every minute (for example, `wp cron event run --due-now --path=/path/to/dashboard`). Use **Refresh metrics now** for an immediate check. If `DISABLE_WP_CRON` is enabled, a working external scheduler is required.

## Pro Reports

Create custom tokens using these exact names in Pro Reports, then place them in a report template. The `mainwp_pro_reports_custom_tokens` filter fills their values for each site.

| Token | Value |
| --- | --- |
| `[kuma.status]` | Current monitor status |
| `[kuma.response_ms]` | Latest response time as a number, in milliseconds |
| `[kuma.uptime.1d]` | Rolling 24-hour percentage |
| `[kuma.uptime.30d]` | Rolling 30-day percentage |
| `[kuma.uptime.365d]` | Rolling 365-day percentage |
| `[kuma.checked_at]` | Last successful collection time in UTC |

**These windows end at the latest collection, not at the report’s selected end date.** They cannot represent an arbitrary historical interval or an exact calendar month. Label them accordingly and include the collection timestamp. Missing measurements return `Unavailable`; stale measurements return `Unavailable (Kuma data stale)`. The collection timestamp still shows when the last successful collection occurred.

The token callback is tested against the documented Pro Reports filter contract. A licensed Pro Reports installation and generated PDF were not available for end-to-end verification.

## Credentials and network behavior

The stored key is encrypted using OpenSSL AES-256-GCM and a key derived from WordPress salts. Back up those salts; changing them requires re-entering the Kuma key. The saved key never appears in rendered forms. Alternatively, provide it outside the database in `wp-config.php`:

```php
define('KMW_API_KEY', 'your-metrics-api-key');
```

Only administrators can configure the instance and view its entire monitor inventory. The Sites column follows MainWP's site permission checks. Requests use Basic authentication with an empty username and the key as password, validate HTTPS certificates, never follow redirects, time out after 10 seconds, and cap responses at 5 MB. Private addresses are intentionally supported because Kuma is self-hosted. HTTP requires explicit opt-in and should only be used on a trusted private network. Nothing is sent to a third-party service by this add-on.

Deactivate to stop scheduled refreshes. Deactivation keeps configuration; deleting the plugin removes its credentials, mappings and cached metrics. Uninstalling does not remove the monitors previously created in Kuma or change MainWP sites.

## Development and verification

```sh
php tests/run.php
php tests/security.php
php tests/package.php
php scripts/package.php
php scripts/package.php --source
```

These checks are standalone. WordPress/MainWP/Kuma integration suites require the explicitly marked disposable environment and opt-in described in [tests/README.md](tests/README.md). They intentionally alter its plugin settings and create synthetic sites; never run them against a real dashboard. Development tools reject web execution. GitHub checks cover syntax, standalone tests, real-service integration and secret scanning.

Verified locally with PHP 8.5.2, WordPress 7.1.2, MainWP 6.2 and MariaDB 11.4, using metrics fixtures and a real disposable Kuma 2.5.5 instance for management. Tests cover parser behavior, URL ambiguity, encryption, transport bounds, stale state, a separate-process settings race, mappings, permissions, MainWP registration/columns and report token output. Browser checks cover save/test, mapping persistence and MainWP integration. Management tests cover actual creation, preserving paused monitors, repeated submissions, lost acknowledgements, pending recovery, connection changes and encrypted sessions. Production systems were not changed during development.

Editing or pausing existing monitors, full outage history and historical report calculations are outside this release’s scope. This is an independent add-on, not an official MainWP or Uptime Kuma product.

## Sources

- [Kuma metrics implementation, 2.5.5](https://github.com/louislam/uptime-kuma/blob/2.5.5/server/prometheus.js)
- [Kuma internal API](https://github.com/louislam/uptime-kuma/wiki/Internal-API)
- [Kuma metrics API keys](https://github.com/louislam/uptime-kuma/wiki/Prometheus-API-Keys)
- [MainWP extension example](https://github.com/mainwp/mainwp-hello-world-extension)
- [MainWP Pro Reports custom-token hook](https://mainwp.dev/hooks/mainwp_pro_reports_custom_tokens/)

Licensed under GPL-2.0-or-later.

See [CONTRIBUTING.md](CONTRIBUTING.md) for development, [SECURITY.md](SECURITY.md) for private vulnerability reporting and trust boundaries, [CHANGELOG.md](CHANGELOG.md) for changes, and [the release guide](docs/RELEASING.md) before publishing. Developer links refer to the source repository; the installable plugin ZIP intentionally excludes development tools.
