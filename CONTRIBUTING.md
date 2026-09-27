# Contributing

Bug reports and focused pull requests are welcome. For security vulnerabilities, use [the private reporting process](SECURITY.md).

Include the plugin, WordPress, PHP, MainWP and Kuma versions, the smallest reproduction and the expected result. Use synthetic sites and redact keys, session tokens, cookies, passwords and customer data. Screenshots of real dashboards can disclose private monitor details.

## Local development

Use PHP 8.1+ with OpenSSL and ZipArchive. The plugin has no Composer, Node or third-party PHP runtime dependencies. Never develop or run tests against a production dashboard.

```sh
php tests/run.php
php tests/security.php
php tests/package.php
php scripts/package.php
php scripts/package.php --source
```

The integration environment and its explicit opt-in are described in [tests/README.md](tests/README.md). Run those suites for changes to permissions, credentials, requests, settings, matching, MainWP integration or monitor creation. Add a regression that fails before a bug fix and passes afterward. GitHub checks cover PHP 8.1–8.5 for standalone suites; the full service integration job uses PHP 8.3.

Keep changes small. Use the existing namespace and file layout; escape rendered content, prepare database queries, and validate capabilities and nonces at HTTP entry points. Never store plaintext credentials or retry uncertain remote creation requests automatically. Do not introduce per-site network requests while rendering tables or reports.

Update README/change notes when behavior changes. Add intended release files explicitly to `scripts/release-files.json`. Test both plugin and source archives. Never add `.dev`, `.env`, database dumps, real credentials or production screenshots to Git. Dependencies and CI actions require a security/license review; pin actions to complete commit hashes.

By contributing, you agree to license your contribution under the project's GPL-2.0-or-later license. There is no separate CLA. Keep discussions respectful and describe disagreements with concrete technical evidence.
