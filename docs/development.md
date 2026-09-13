# Development

How to run the plugin from source, what tools you need, and the day-to-day commands you'll use.

For contribution rules (branch naming, commit conventions, PR workflow), see [`CONTRIBUTING.md`](../CONTRIBUTING.md). For the test-and-quality stack, see [`testing-and-quality.md`](testing-and-quality.md). For releases, see [`release.md`](release.md).

## What you need

| Tool | Why |
| --- | --- |
| **Docker** (Docker Desktop on macOS/Windows or Docker Engine on Linux) | Runs `wp-env` (the local WordPress stack) and the PHP toolchain (PHPCS, PHPStan, Psalm, PHPUnit, WP-CLI) without forcing you to install matching PHP extensions on the host. |
| **Node.js 18+** and **npm** | Boots `wp-env` (`npm run env:start` / `make env`). |
| **`make`** | Optional but recommended — wraps every common task behind a short target. See `make help`. |
| **`gh`** (GitHub CLI) | Optional — convenient for working with issues, PRs, and CI logs. |

You do **not** need PHP installed on the host. Every PHP-based command in this repo (PHPCS, PHPStan, Psalm, PHPUnit, WP-CLI) runs inside an official Docker image, mounted as your host UID so the `vendor/` directory doesn't end up root-owned.

If you do have a full PHP CLI locally (with `dom`, `mbstring`, `xml`, `xmlwriter`, `libxml`, `openssl`, `json`, `fileinfo`, `tokenizer` extensions), you can opt out of Docker via `make DOCKER=0 ...` to skip the image overhead.

## First run

```bash
git clone https://github.com/soydiloreto/diluxone-offload-wordpress.git
cd diluxone-offload
make install     # composer install — pulls dev tooling into vendor/
make env         # boots wp-env at http://localhost:8888
```

When `make env` finishes, open <http://localhost:8888>. Log in with `admin` / `password`. The plugin is already mounted at `wp-content/plugins/diluxone-offload/` — activate it from the **Plugins** screen.

## Day-to-day commands

| Command | What it does |
| --- | --- |
| `make help` | List every available target with a one-line description (this is the default — `make` with no argument). |
| `make install` | `composer install` — populate `vendor/`. |
| `make env` / `make env-up` | Start `wp-env`. |
| `make env-down` | Stop `wp-env` (preserves the database). |
| `make env-clean` | Destroy `wp-env` and its volumes — use this if your dev install gets into a bad state. |
| `make lint` | PHPCS with WordPress Coding Standards. |
| `make lint-fix` | PHPCBF — auto-fix the violations PHPCS can repair. |
| `make stan` | PHPStan level 8 (no baseline). |
| `make psalm` | Psalm taint analysis (XSS / SQLi / RCE). |
| `make i18n` | `wp i18n make-pot` — extract the translatable strings into `build/diluxone-offload.pot`. |
| `make test` | Run the unit-test suite (the default test target — fast, no WordPress runtime needed). |
| `make test-integration` | Run the integration-test suite against `wp-env` (must be `make env` first). |
| `make check` | Run every quality gate CI runs: lint + stan + psalm + tests. |
| `make deploy-test` | Sync the current branch into `~/repos/mug-website-v2/wp-content/plugins/diluxone-offload/` for a smoke test on a real site (the script lives at `~/.local/bin/diluxone-offload-deploy-to-mug` by default — override with `DEPLOY_SCRIPT=/path/to/script`). |
| `make release` | `make check` plus a version-alignment dry-run; fails if the PHP `Version:` header and the `readme.txt` `Stable tag:` would not match at tag time. |
| `make clean` | Wipe caches and build artefacts. |

## Configuration

The default `wp-env` setup is in [`.wp-env.json`](../.wp-env.json):

- **WordPress core**: latest stable.
- **PHP version**: 8.2.
- **Plugin**: this repository, auto-mounted.
- **Debug mode**: `WP_DEBUG`, `WP_DEBUG_LOG`, and `SCRIPT_DEBUG` enabled. `WP_DEBUG_DISPLAY` is off so errors don't render in pages — they go to `wp-content/debug.log` instead.

To override any of these on your local machine without committing the changes, create a `.wp-env.override.json` file (it's already in `.gitignore`). See the [`@wordpress/env` docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) for the full schema.

## Manual install (alternative)

If you'd rather use your own WordPress setup instead of `wp-env`, clone this repo directly into `wp-content/plugins/diluxone-offload/` of your existing WordPress install. The plugin has no build step — it runs straight from source.

You'll lose the convenience of `make env*`, but `make lint` / `make stan` / `make test` / etc. all work exactly the same, because they don't depend on a running WordPress.

## Docker image overrides

The Makefile uses these images by default:

| Variable | Default | Where it's used |
| --- | --- | --- |
| `COMPOSER_IMAGE` | `composer:2` | `make install`, `make lint`, `make stan`, `make test*` |
| `WP_CLI_IMAGE` | `wordpress:cli` | `make i18n` |
| `PHP_IMAGE` | `php:8.3-cli` | `make psalm` (Psalm needs PHP ≤ 8.3) |
| `DOCKER_NET` | `--network host` | `make i18n`, `make test-integration` |

Pin any of them for byte-for-byte reproducibility — e.g. `make stan COMPOSER_IMAGE=composer:2.7.7`. The defaults stay floating because pinning to a digest in-repo would force a Makefile change every time the upstream image moves; the override is there for users who need it.

`--network host` is **not** supported on Docker Desktop for macOS or Windows. If you're on those platforms, set `DOCKER_NET=` (empty) and arrange WP-CLI / integration test connectivity another way (e.g. by running them inside the `wp-env` container).
