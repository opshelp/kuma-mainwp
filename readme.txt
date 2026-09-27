=== Kuma Monitor for MainWP ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private Uptime Kuma metrics in MainWP, with site matching, cached status and rolling uptime report tokens.

== Description ==
Install on the MainWP Dashboard WordPress site. Connect a Kuma base URL and metrics API key under Settings > Kuma Monitor. The plugin fetches /metrics over HTTP(S) and adds a Kuma column to MainWP Sites.

Supports status, response time, rolling 24-hour/30-day/365-day uptime and certificate expiry when exposed by Kuma. The endpoint must include monitor_id labels; Uptime Kuma 2.5.5 is the target format. MainWP Dashboard 5.0+ is required for site integration. Pro Reports 5.1.4+ is recommended for optional custom tokens.

Uptime windows are rolling and do not follow a report's date range. Optionally connect Kuma management to create monitors for selected missing MainWP sites. Existing monitor settings are preserved. Exact historical reports and editing existing monitors are not included. See README.md for setup, credentials, cron requirements, tokens and testing limits.

No third-party service receives data. The plugin sends credentials to the administrator-configured Kuma instance only. Metrics keys and optional management session tokens are encrypted at rest using WordPress salts; management passwords and two-factor codes are not stored. Deleting the plugin removes its local data.

== Installation ==
1. Upload and activate the ZIP on the MainWP Dashboard site.
2. Open Settings > Kuma Monitor.
3. Enter the Kuma base URL and metrics API key; save and test.
4. Review mappings. Enable the Kuma column in MainWP Page Settings if necessary.
5. Ensure WP-Cron executes every minute.

== Changelog ==
= 0.2.1 =
Security and public release hardening: atomic locks, serialized mapping/settings changes, endpoint-bound management sign-in, guarded development tools and explicit release manifests.

= 0.2.0 =
Selected monitor creation with a separate management sign-in, full inventory including paused monitors, duplicate checks, and recovery after interrupted adds. Refresh and save actions return to the page they were submitted from.

= 0.1.0 =
Initial release: private metrics connection, site mapping, status columns, cached polling and rolling Pro Reports tokens.
