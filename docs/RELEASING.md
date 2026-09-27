# Preparing a public GitHub release

The project is an independent GPL-2.0-or-later add-on. It is not an official MainWP or Uptime Kuma product. No publication credentials or automation that publishes releases are included.

## First publication

1. Use the **source archive** produced by `php scripts/package.php --source` to start a clean repository. It includes source, tests, documentation and GitHub configuration, without local test installations, internal development notes or `.git` history. The WordPress plugin archive contains only runtime files and user documentation.
2. Choose the repository owner/name and review your Git author name/email before the first public commit. A GitHub no-reply email avoids publishing a personal address. Existing private development history is not rewritten by the export.
3. Enable [private vulnerability reporting](https://docs.github.com/en/code-security/how-tos/report-and-fix-vulnerabilities/configure-vulnerability-reporting/configure-for-a-repository) before opening the repository to reports. Verify the **Report a vulnerability** button works. Add a private contact to SECURITY.md if that feature is unavailable.
4. Enable secret scanning/push protection where available. Set repository Actions permissions to read-only and require pull requests with passing `PHP`, `Integration` and `Secrets` checks on the default branch. Use hosted runners for public pull requests. Review collaborator access and require two-factor authentication where the account/organization allows it.
5. Push the source and inspect the first hosted CI results before tagging a release. The workflows need no repository secrets. Local verification does not establish that hosted CI has passed.

## Each release

- Update the plugin header, `KMW_VERSION`, readme stable tag, installation example, security support statement and changelog.
- Run all suites in [tests/README.md](../tests/README.md), PHP lint, and both package commands. Test the resulting plugin ZIP in a disposable WordPress/MainWP installation, including MainWP refresh redirects and selected creation against the supported Kuma version.
- Run Gitleaks against the full history and the extracted source/archive contents. Review reported findings; never hide a real secret with a broad allowlist. Also inspect screenshots and text for personal/customer information, which a secret scanner may not recognize.
- Check the archive file list and checksum. Tests, local environments and build tools must not appear in the WordPress plugin ZIP. A source archive must not contain `.git`, local credentials or build outputs.
- Tag the reviewed commit and attach the plugin ZIP and its `.sha256` file to a GitHub release. Explain compatibility changes and fixes in the release notes. Do not tell WordPress users to install GitHub's automatically generated source ZIP as the normal release package.

Builds use fixed file order, timestamps and file permissions. The same files and toolchain produce the same archive bytes; compression-library differences can change hashes across environments. The adjacent SHA-256 file checks integrity, not publisher identity or authenticity.
