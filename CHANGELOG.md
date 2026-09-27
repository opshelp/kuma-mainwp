# Changelog

## 0.2.1 — 2026-09-27

- Use atomic database locks for polling and monitor creation. Serialize connection and mapping saves with creation batches to preserve concurrent edits.
- Bind management sign-in credentials to the endpoint represented by the submitted form before making network requests.
- Block HTTP execution of development tools and require explicit opt-in and a marked disposable site for mutating tests.
- Build plugin and clean source archives from exact file allowlists; reject symlinked release files.
- Add a security policy, contributor guide, release instructions, GitHub templates, pinned CI checks and secret scanning.

## 0.2.0 — 2026-09-27

- Add an optional management connection to create selected missing MainWP monitors.
- Reuse full inventory, including paused monitors; preserve existing settings and reject ambiguous/related duplicates.
- Journal uncertain creation attempts and provide explicit recovery after checking Kuma.
- Return refresh and save actions to their originating MainWP or WordPress Settings page.

## 0.1.0 — 2026-09-27

- Connect private Kuma metrics with encrypted API-key storage and cached polling.
- Match monitors to MainWP sites, with manual overrides and stale-data handling.
- Add a MainWP status column and rolling uptime tokens for Pro Reports.
