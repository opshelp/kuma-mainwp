# Local integration test environment

The standalone checks `php tests/run.php`, `php tests/security.php` and `php tests/package.php` do not require WordPress. PHP needs OpenSSL and ZipArchive. The security checks briefly start a server bound to a random loopback port; packaging checks use a temporary directory.

The integration tests below modify data. Use a disposable local site only. Paths are relative to this repository. Before running them, set `export KMW_TESTS_ALLOW_DESTRUCTIVE=1` in your test shell and add `define('KMW_TEST_INSTANCE', true);` to the disposable `wp-config.php`. Both gates are required. `KMW_TEST_WORDPRESS` may point to an alternative disposable WordPress directory; otherwise `.dev/wordpress` is used. The scripts refuse HTTP execution.

1. Download WordPress from `https://wordpress.org/latest.zip` into `.dev/wordpress` and WP-CLI from its official builds repository into `.dev/wp-cli.phar`.
2. Start an isolated MariaDB 11.4 container with database `kuma_test`, listening only on `127.0.0.1:8879`. Set a local password, then configure WordPress with those credentials. MainWP uses mysqli directly, so SQLite is not sufficient.
3. Install WordPress with URL `http://127.0.0.1:8771` and administrator ID 1. Set both `siteurl` and `home` to this URL. Set `DISABLE_WP_CRON=true` so fixture sites cannot trigger background MainWP tasks.
4. Download and activate MainWP from WordPress.org. Link this repository to `.dev/wordpress/wp-content/plugins/kuma-mainwp` and activate it. No actual child-site credentials are needed.
5. Run these servers in separate terminals:

```sh
php -S 127.0.0.1:8771 -t .dev/wordpress
php -S 127.0.0.1:8772 tests/metrics-server.php
```

6. Run `php tests/run.php`, `php tests/integration.php`, then `php tests/mainwp.php`. The final suite seeds three synthetic MainWP site rows, connects to the fixture server, and leaves the dashboard ready for browser inspection. The fixture key is deliberately public test data, not a production secret.

The integration suite tests the real WordPress HTTP request construction using `pre_http_request` for controlled failures. The MainWP suite uses actual HTTP against the fixture server. A separate PHP process reproduces a concurrent settings update. Team Control permissions use MainWP's real permission filter; the paid extension itself is not installed.

Browser checks: log in; open Settings → Kuma Monitor; save/test; change/save a mapping; verify MainWP Sites' Kuma column; verify the add-on opens from MainWP. After checking, stop both servers and the disposable database/VM.

Check runtime syntax with PHP lint. Build the release with `php scripts/package.php`; `.dev`, tests, repository history and private credentials are excluded by the exact `scripts/release-files.json` allowlist. `php scripts/package.php --source` also produces a clean public source archive containing tests and GitHub configuration. See [the release guide](../docs/RELEASING.md).

## Real Kuma management tests

Use a second disposable container, `louislam/uptime-kuma:2.5.5`, exposed only at `127.0.0.1:8878:3001`. Set `UPTIME_KUMA_DB_TYPE=sqlite` for automatic database setup. On an empty instance, `php tests/setup-kuma.php` initializes its first user as `kmw_test` with the deliberately public fixture password `Kuma-local-provisioning-2026!`. Never reuse this password elsewhere or expose this container publicly. Setup deliberately fails if a user already exists; it does not reset or delete data.

Run `php tests/provisioning.php`. This suite connects management, creates synthetic `.example` monitors, preserves and verifies paused monitors, checks repeat submissions, and drops a real HTTP acknowledgement to exercise recovery. It also tests failed sends, administrator recovery, lock contention, and a database journal write failure. Tests generally remove their created monitors on success; failed runs can leave fixtures in this disposable Kuma instance. It must not contain real monitors or notification recipients. The suite changes the plugin connection and ends with management disconnected.

Browser checks for 0.2.0: sign in to management from the MainWP add-on page; select one fixture site and create it; verify a second submission produces no duplicate; refresh metrics and ensure the URL remains `admin.php?page=Extensions-Kuma-Mainwp`; check the Settings page also returns to itself. Test the packaged ZIP after replacing the development symlink.

The GitHub workflow provisions MariaDB, WordPress 7.1.2, MainWP 6.2 and Kuma 2.5.5 on an ephemeral hosted runner and runs the same integration commands against the packaged plugin. Fixture passwords in that workflow are intentionally public and must never be used outside this disposable environment. No production credentials or repository secrets are needed.
