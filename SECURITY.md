# Security policy

## Reporting a vulnerability

Use **Security → Advisories → Report a vulnerability** on this project's GitHub repository to contact the maintainer privately. Include affected versions, reproduction steps, impact and a minimal example with synthetic data. Do not include live credentials, customer details or database exports.

If private reporting is unavailable, open an issue asking the maintainer to enable a private reporting channel, without disclosing the vulnerability. Do not post exploit details in a public issue before coordinated disclosure.

Security fixes target the latest release. Upgrade to **0.2.1 or later**; older versions have known concurrency defects affecting creation locks, mapping updates and the destination used during management sign-in. There is no guaranteed response or patch timeline.

## Trust boundaries

- Install only on a trusted MainWP Dashboard. Configuration, full inventory and creation actions require WordPress `manage_options`; form submissions also require POST and a valid nonce. MainWP site permissions control the status column.
- Metrics keys are read-only. Optional management sign-in creates a **full Kuma account session**, not a narrowly scoped “create monitor” token. The plugin uses it for inventory and selected creation, but theft of that token can expose the account's wider permissions. Disconnect management when it is not needed.
- Stored metrics keys and management tokens use AES-256-GCM with separate keys derived from WordPress salts. Passwords and two-factor codes are not persisted. Encryption protects a database-only disclosure; it cannot protect credentials from someone who also controls WordPress, its salts, PHP execution or backups containing both. Changing salts requires reconnecting.
- Disconnect removes the local token; it does not revoke copies of a Kuma session. Use Kuma's account security controls if a token may have been exposed. Removing the plugin does not delete remote monitors.
- Requests verify TLS, do not follow redirects, and have time and response-size limits. HTTPS is the default. HTTP is an explicit administrator opt-in and sends credentials without transport encryption; use it only on a trusted private network.
- Private network addresses are deliberately supported. A trusted administrator chooses the Kuma destination. Restrict the dashboard's outbound network access at the host/firewall if stricter isolation is required. The plugin is not a network sandbox.
- MainWP site URLs are sent to Kuma when an administrator selects them for creation. Kuma then makes monitoring requests and follows redirects according to its monitor settings. Review site URLs and notification selections before creating monitors.
- Metrics and sanitized inventories contain site/monitor names, URLs and status information in the WordPress database. Do not put credentials in monitor URLs. Existing monitors and notification configuration are never edited by this plugin.

## Reliability limits relevant to security

Local creation batches and settings/mapping writes use a shared atomic database lock. Expired locks are recoverable, and uncertain remote writes remain pending until reviewed. This does not provide a transaction or URL uniqueness across independent Kuma clients. Avoid simultaneous imports from multiple dashboards/tools.

Management uses Kuma's internal API, verified with 2.5.5. Review compatibility before upgrading Kuma. No plugin can guarantee security of the surrounding WordPress, MainWP, Kuma, proxy or hosting installation.

## Development and release controls

Development entry points reject web execution. Mutating suites require an explicit environment opt-in and a marked disposable WordPress installation. Public fixture credentials are for local tests only. Release archives use an explicit file allowlist, reject symlinks, and exclude local environments and Git history.

GitHub checks run PHP syntax/unit/security/package checks, WordPress/MainWP/Kuma integration tests, and Gitleaks against history. Actions are pinned to commits and run with read-only repository permissions and no deployment credentials. Passing checks are evidence, not a guarantee or an independent penetration-test certification.
