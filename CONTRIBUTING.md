# Contributing to DiluxOne Offload

Thanks for your interest in contributing. This document covers how to report issues, submit pull requests, and what to expect from the review process.

## Reporting bugs and requesting features

Open a [new issue](https://github.com/soydiloreto/diluxone-offload-wordpress/issues/new/choose) and pick the appropriate template:

- **Bug report** — something is broken or behaves unexpectedly.
- **Feature request** — you'd like the plugin to do something it doesn't do today.

For **end-user support questions** (how do I configure this?, my upload is not working, etc.) please use the [wp.org support forum](https://wordpress.org/support/plugin/diluxone-offload/) instead — that's where most users look for answers and where we maintain a public Q&A.

For **security vulnerabilities**, see [SECURITY.md](SECURITY.md). Do not open a public issue.

## Pull request workflow

1. **Fork** the repository and clone your fork locally.
2. **Branch** from `main` using a descriptive name following the convention below.
3. **Commit** your changes (see commit message conventions below).
4. **Push** the branch to your fork.
5. **Open a pull request** against `main`. Fill in the PR template — explain *why* the change matters, not just *what* it does.
6. **Wait for CI to pass.** All required checks must be green before review.
7. **Address review feedback** by pushing additional commits to the same branch (we squash on merge, so commit count doesn't matter).

### Branch naming

Use one of these prefixes, followed by a short kebab-case description:

| Prefix | Used for |
|--------|----------|
| `feat/` | New user-visible functionality |
| `fix/` | Bug fixes |
| `chore/` | Maintenance tasks, version bumps, no behavior change |
| `docs/` | Documentation-only changes |
| `ci/` | CI/CD configuration changes |
| `refactor/` | Internal restructure with no behavior change |
| `style/` | Code style / linter / formatting changes |

Examples: `feat/s3-provider`, `fix/decrypt-fallback-banner`, `docs/contributing-guide`.

### Commit messages

We follow [Conventional Commits](https://www.conventionalcommits.org/). The first line is `<type>(<optional-scope>): <subject>`, where type is one of `feat`, `fix`, `chore`, `docs`, `ci`, `refactor`, `style`, `test`. Examples:

```
feat(provider): add S3 cloud storage provider
fix(admin): surface decrypt failures in the connection-health banner
chore: bump to 1.2.0
```

The body explains the *why* — context, motivation, alternatives considered. Wrap at ~72 characters.

## Coding conventions

The project enforces a strict quality stack on every PR. Run `make check` locally before pushing — it runs the same gates CI does.

| Gate | Tool | Make target |
| --- | --- | --- |
| Code style | PHP_CodeSniffer + WordPress Coding Standards (full ruleset, no relaxations) | `make lint` (auto-fix: `make lint-fix`) |
| Static analysis | PHPStan level 8, no baseline | `make stan` |
| Security taint analysis | Psalm in taint-only mode (XSS, SQLi, RCE) | `make psalm` |
| i18n | `wp i18n make-pot` + warning-grep | `make i18n` |
| Unit tests | PHPUnit + brain/monkey + mockery | `make test` |
| Integration tests | PHPUnit against `wp-env` | `make test-integration` |

See [`docs/testing-and-quality.md`](docs/testing-and-quality.md) for what each layer enforces and the configuration files that drive it.

A few hard rules the linters can't fully express:

- **PHP 7.4+** is the minimum supported version. Do not use syntax or functions added in later versions without a fallback. (PHPCS's PHPCompatibility ruleset catches most of this.)
- **No hard dependencies on Composer packages** in the runtime path. The plugin must run on a fresh WordPress install with no extra setup. `composer install` produces only dev tooling — `vendor/` never ships to wp.org.
- **All user-facing strings** must be wrapped in WordPress translation functions (`__()`, `_e()`, `_n()`, etc.) with the text domain `diluxone-offload`. `sprintf()` placeholders need a `/* translators: */` comment **on the line immediately preceding** the translation call (a blank line in between makes the comment invisible to gettext).
- **All user input** must be sanitised (`sanitize_text_field`, `wp_kses`, etc.) and all output must be escaped (`esc_html`, `esc_attr`, `esc_url`). Psalm taint analysis enforces this for the obvious sinks; PHPCS catches the rest.
- **Never log credentials.** API keys, access keys, and decrypted secrets must not appear in `error_log` even when debug mode is on. PHPStan can't catch this — be deliberate.

## Versioning

We follow [Semantic Versioning](https://semver.org/) for the plugin's public version (`MAJOR.MINOR.PATCH`):

- **PATCH** (1.1.0 → 1.1.1): bug fixes only, no behavior change for the user beyond the fix itself.
- **MINOR** (1.1.0 → 1.2.0): new user-visible functionality, backwards-compatible.
- **MAJOR** (1.x → 2.0): backwards-incompatible changes (rare; plugins try hard to avoid).

Repository-only changes (this CONTRIBUTING.md, CI workflows, dev tooling, etc.) **do not** trigger a version bump — they are excluded from the wp.org deploy via [`.distignore`](.distignore) and are invisible to end users.

### `-dev` suffix on `main`

The `Version:` header in `diluxone-offload.php` (and the
`DILUXONE_OFFLOAD_VERSION` constant alongside it) carry a **`-dev` suffix on
`main`** to signal that the working tree is in active development and
not a tagged release. The pattern matches what Symfony, Laravel,
WordPress core, npm packages, and most other professional open-source
projects use:

| Where | Looks like | Means |
|---|---|---|
| `main` between releases | `Version: 1.2.0-dev` | "Working towards 1.2.0; this is NOT a release" |
| Final release commit | `Version: 1.2.0` | "This commit IS the 1.2.0 release; tag it" |
| Tag (e.g. `1.2.0`) | snapshot of the release commit | What ends up at wp.org and on user sites |
| `main` after release | `Version: 1.3.0-dev` | "Now working towards 1.3.0" |

Why: a developer who clones `main` between releases sees `1.2.0-dev`
and immediately knows they are NOT looking at the published version.
Without the suffix, the same clone would show `1.2.0`, indistinguishable
from the actual published 1.2.0 release.

The `Stable tag:` in `readme.txt` does NOT carry the suffix — it always
holds the **last published release version** (or, before any release,
the next intended one). The CI's version-alignment check accepts this
asymmetry: it strips the suffix from the PHP `Version:` header before
comparing to `Stable tag`.

Concretely:

- Open a PR that adds a feature: leave `Version: 1.2.0-dev` alone.
- When ready to release: in a final "release prep" PR, change
  `Version: 1.2.0-dev` → `Version: 1.2.0` (drop the suffix), update
  `Stable tag: 1.2.0` if needed, add the `= 1.2.0 =` changelog entry.
- Push the tag `1.2.0` after merge — the deploy workflow handles wp.org.
- In a follow-up PR, bump `Version: 1.3.0-dev` (or whatever the next
  planned release is) on `main`.

Accepted pre-release suffixes are `-dev`, `-alpha`, `-beta`, `-rc`
(optionally followed by `.N`). All four signal "not a release" to the
CI version-alignment rule and to humans reading the file.

## Releasing (maintainers only)

Release flow is documented for maintainers. Briefly:

1. All PRs targeting the next release are merged into `main`.
2. The maintainer drops the `-dev` suffix in `diluxone-offload.php`
   (`Version:` header and `DILUXONE_OFFLOAD_VERSION` constant), updates
   `Stable tag:` in `readme.txt` if needed, and adds a `== Changelog ==`
   entry. This is typically a single small "release prep" PR.
3. The maintainer creates and pushes a git tag matching the version
   (bare number, no `v` prefix — e.g. `1.2.0`).
4. The GitHub Action `deploy.yml` automatically pushes the release to
   wp.org SVN and uploads a zip to GitHub Releases.
5. After the release lands on wp.org, the maintainer opens another PR
   that bumps `Version:` to `1.3.0-dev` (or whatever the next intended
   release is) so `main` reflects "in development" again.

## AI-assisted contributions

If you use Copilot, Claude, GPT, Cursor, or any other AI tool to help write code, read [`docs/ai-policy.md`](docs/ai-policy.md) before opening a PR. The summary: use any tool you want, but you sign the commit and you own the code — every line, every test, every behaviour. The model isn't going to answer the bug report.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By participating, you agree to abide by its terms.

## License

By contributing, you agree that your contributions will be licensed under the [GPL-2.0-or-later](LICENSE).
