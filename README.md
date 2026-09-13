# DiluxOne Offload

> Offload WordPress media to cloud object storage (Azure Blob, DiluxOne Cloud) with a transparent PHP stream wrapper — no URL rewriting, no database migration.

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

## What is this?

DiluxOne Offload is a WordPress plugin that moves your media library to cloud object storage and serves files directly from the cloud — without breaking the Media Library UI, third-party plugins, or existing post content.

It uses a **PHP stream wrapper** to intercept every read and write to `/wp-content/uploads/`, so WordPress, WooCommerce, page builders, image editors, and any plugin that calls standard filesystem functions (`fopen`, `file_get_contents`, `unlink`, etc.) keep working unchanged.

## For end users

If you just want to **install and use** the plugin on your WordPress site, get it from the official directory:

🔗 **[wordpress.org/plugins/diluxone-offload](https://wordpress.org/plugins/diluxone-offload/)** *(coming soon — pending wp.org review)*

User-facing documentation (features, installation, configuration, FAQ) lives in [`readme.txt`](readme.txt) — that's the version rendered on the wp.org plugin page.

## For developers

This README and the rest of this repository are aimed at developers who want to **contribute, fork, or run the plugin from source**.

## Quick start (developers)

```bash
git clone https://github.com/soydiloreto/diluxone-offload-wordpress.git
cd diluxone-offload
make install     # composer install — populate vendor/
make env         # boots wp-env at http://localhost:8888
```

When it finishes, open <http://localhost:8888>. Log in with `admin` / `password`. The plugin is already mounted at `wp-content/plugins/diluxone-offload/` — activate it from the **Plugins** screen.

`make help` lists every available target. For the full setup walkthrough, the day-to-day commands, the Docker plumbing, and the `wp-env` configuration, see [`docs/development.md`](docs/development.md).

## Architecture overview

| Path | What it contains |
|------|------------------|
| `diluxone-offload.php` | Main plugin file — bootstraps everything and loads `includes/`. |
| `includes/` | Core PHP classes: config manager, stream wrapper, sync engine, admin pages, REST handlers. |
| `templates/` | Admin page views (rendered by the admin classes). |
| `assets/` | Plugin runtime assets — JS, CSS, images bundled with the plugin. |
| `languages/` | Translations: `es_AR`, `es_ES`, `es_MX`, `pt_BR`, `pt_PT`, `fr_FR`, `de_DE`, `it_IT`. |
| `.wordpress-org/` | wp.org listing visuals — banner, icon, screenshots. Uploaded by CI to the SVN `assets/` directory, **not** to the published plugin zip. |
| `readme.txt` | wp.org plugin page content (description, FAQ, changelog). |
| `.github/workflows/` | CI/CD: deploy to wp.org SVN on tag push, plus PR checks. |
| `.distignore` | List of paths excluded from the wp.org deploy (this README, dev tooling, etc. live in GitHub but are never shipped to wp.org). |

## Documentation

For developers working on the plugin itself:

- [`CONTRIBUTING.md`](CONTRIBUTING.md) — branch naming, PR workflow, commit conventions, coding rules.
- [`docs/development.md`](docs/development.md) — local dev setup (`wp-env`, Docker, Make targets).
- [`docs/testing-and-quality.md`](docs/testing-and-quality.md) — PHPUnit, PHPCS, PHPStan, Psalm, i18n. What each layer enforces and how to run it.
- [`docs/ai-tooling.md`](docs/ai-tooling.md) — what AI tooling the project uses (Copilot Code Review and friends), what it costs, and what it's configured to enforce.
- [`docs/ai-policy.md`](docs/ai-policy.md) — rules for contributors using AI agents to author code.
- [`docs/release.md`](docs/release.md) — version bump flow, the `-dev` suffix convention, the wp.org SVN deploy.

## Contributing

Contributions are welcome — bug reports, feature requests, and pull requests. See [`CONTRIBUTING.md`](CONTRIBUTING.md) to get started.

## Reporting security issues

⚠️ **Please do not open public issues for security vulnerabilities.** See [SECURITY.md](SECURITY.md) for the private reporting process via GitHub Security Advisories.

## About

This plugin is **free and open-source software** under the GPL-2.0-or-later licence — that includes the cloud-offloading code, the stream wrapper, the admin UI, every test, every CI workflow.

It was created and is currently maintained by **Pablo Diloreto** ([@soydiloreto](https://github.com/soydiloreto)). Contributions, issues, and forks are all welcome.

The plugin ships with two cloud-storage backends: a generic **Azure Blob Storage** integration that you can point at any Azure account you control, and an integration with **DiluxOne Cloud**, a paid managed offering. Both backends use the same plugin code and the same stream wrapper — using the DiluxOne backend is one option among several; the plugin is fully usable end-to-end with Azure (or any future provider) and never disables features behind a paywall. The whole point of writing this open is so that it isn't necessary to use any specific backend.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
