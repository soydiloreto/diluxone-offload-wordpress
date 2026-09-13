# Testing & quality

What every quality gate enforces, why, and how to run each one locally.

## Quality stack at a glance

| Layer | Tool | Catches | CI workflow | Make target |
| --- | --- | --- | --- | --- |
| Unit tests | PHPUnit + brain/monkey + mockery | Logic regressions in pure-PHP units. | `pr-checks.yml` | `make test` |
| Integration tests | PHPUnit + wp-env | Behaviour against a real WordPress runtime + DB. | `pr-checks.yml` | `make test-integration` |
| Coding style | PHP_CodeSniffer + WordPress Coding Standards | Style, naming, escaping, sanitisation, prepared statements, deprecated APIs. | `pr-checks.yml` | `make lint` |
| Static analysis | PHPStan level 8 + szepeviktor/phpstan-wordpress | Type safety, unreachable code, undefined methods/properties, missing return types. **No baseline.** | `tests-stan.yml` | `make stan` |
| Security taint analysis | Psalm + humanmade/psalm-plugin-wordpress (taint-only mode) | XSS, SQL injection, command injection, file-system traversal — user input flowing into dangerous sinks. | `psalm-taint.yml` | `make psalm` |
| i18n | `wp i18n make-pot` | Missing translator comments on placeholders, dynamic text domains, conflicting translator hints, concat'd translatable strings. | `i18n-validate.yml` | `make i18n` |
| Plugin Check (wp.org) | wordpress/plugin-check | The same checks the wp.org plugin team runs at submission/review time. | `pr-checks.yml` | (no Make target — runs on PR) |
| Security supply chain | CodeQL (JS) | Common JS vulnerability patterns. | `codeql.yml` | (no Make target — runs on PR) |

Every layer must pass before a PR can land on `main` (branch protection enforces it).

## Unit tests

Located in [`tests/Unit/`](../tests/Unit/). They run in pure PHP without WordPress — `brain/monkey` stubs out `__()`, `apply_filters`, etc., so a unit test can exercise a class method without booting WordPress.

```bash
make test           # default target → unit tests only
make test-unit      # explicit
```

When you add a new unit test:

- Mirror the source path: a class at `includes/Foo/Bar.php` is tested by `tests/Unit/Foo/BarTest.php`.
- Extend the project's base unit test class, not PHPUnit's directly — it sets up the brain/monkey lifecycle.
- Don't touch `$_GET`, `$_POST`, the database, the filesystem, or `define()` plugin constants. Move that to integration tests instead.

## Integration tests

Located in [`tests/Integration/`](../tests/Integration/). They run inside the `wp-env` Docker stack, against a real WordPress + MySQL.

```bash
make env                # boot wp-env first
make test-integration   # run the integration suite
```

Use these for code paths that genuinely depend on WordPress core: hooks, options, transients, custom tables, AJAX handlers, REST routes. Anything that boils down to "I need `wpdb`" or "I need `apply_filters` to actually apply".

CI runs the same suite (`pr-checks.yml`, the **Integration tests (wp-env)** job) so a pure-Docker contributor can develop against the exact same environment.

## PHPCS / WordPress Coding Standards

Configuration: [`phpcs.xml.dist`](../phpcs.xml.dist).

```bash
make lint           # report violations
make lint-fix       # auto-fix what can be auto-fixed (PHPCBF)
```

The ruleset enforces the WordPress Coding Standards plus a small project-specific overlay:

- **DTOs and Enums** (`includes/DTOs/`, `includes/Enums/`) use modern PSR-12 / PascalCase, not WPCS naming. The rules that conflict with that style are excluded for those paths only.
- **Yoda conditions**, **trailing-comma-in-array**, **base64 encoding** (legitimate for crypto), and a few comment-formatting nits are globally relaxed; everything else is on.
- The version-alignment script tolerates `-dev` / `-alpha` / `-beta` / `-rc` pre-release suffixes by stripping them before comparing the PHP `Version:` header to the readme `Stable tag:` (see [`release.md`](release.md)).

When PHPCS reports a violation, the rule code is in the right column. Search for it in the config or in [WPCS docs](https://github.com/WordPress/WordPress-Coding-Standards/wiki) before suppressing — most warnings are real bugs (missing escaping, missing nonce, missing prepare).

## PHPStan

Configuration: [`phpstan.neon`](../phpstan.neon). Bootstrap stubs: [`phpstan-bootstrap.php`](../phpstan-bootstrap.php).

```bash
make stan
```

We run **level 8 (max strictness) with no baseline.** Every type error must be fixed in code, not suppressed. The `szepeviktor/phpstan-wordpress` extension teaches PHPStan about the WordPress API surface so e.g. `wp_remote_get()` returns `array|WP_Error` and `$wpdb->update()` returns `int|false`.

A few constants are declared `dynamicConstantNames` (`DILUXONE_OFFLOAD_DEV_MODE`, `DILUXONE_OFFLOAD_VERBOSE_LOGGING`, `DILUX_API_URL`, `WP_DEBUG`) so PHPStan does not collapse `if ( DILUXONE_OFFLOAD_DEV_MODE )` into "always false" on the bootstrap stub default. Their runtime values are user-controlled (typically from `wp-config.php`).

If you find a real type error PHPStan can't see (e.g. PHP extension stubs are missing in CI), use `// @phpstan-ignore-next-line <identifier>` with a comment explaining why. Don't add to a baseline — the project deliberately doesn't have one.

## Psalm taint analysis

Configuration: [`psalm.xml`](../psalm.xml).

```bash
make psalm
```

Psalm here runs in **taint-analysis mode only**. The `humanmade/psalm-plugin-wordpress` plugin teaches it that `esc_html()`, `esc_attr()`, `esc_url()`, `wpdb->prepare()`, `sanitize_*()` are sanitisation barriers, so user-controlled values from `$_GET` / `$_POST` / `$_REQUEST` / `$_COOKIE` / `$_FILES` / `$_SERVER` only become findings if they reach a dangerous sink (`echo`, `eval`, `exec`, `$wpdb->query()`, `file_put_contents`, `header`, …) without passing through one.

General static type-checking is suppressed in `psalm.xml` — that's PHPStan's job. Running both as type-checkers would just duplicate failures and obscure real taint findings.

If Psalm flags a path you believe is safe, the right fix is almost always to pipe the value through the appropriate WordPress escaper. Suppressing should be a last resort and must be justified inline.

## i18n validation

Configuration: [`.github/workflows/i18n-validate.yml`](../.github/workflows/i18n-validate.yml).

```bash
make i18n
```

The Makefile target runs `wp i18n make-pot` and writes the result to `build/diluxone-offload.pot`. The CI workflow does the same and additionally fails the build if any `Warning:` / `Error:` line appears in the output (WP-CLI prints them to stderr but exits 0 even when present, so we capture the output and grep ourselves).

The workflow catches three real classes of bug:

- **Missing translator comments** on `sprintf()` placeholders. WordPress requires a `/* translators: %s: ... */` comment **on the line immediately preceding** the translation function call — separating it with a blank line silently makes it invisible to gettext. We learned this the hard way fixing six of these on the first run.
- **Conflicting translator comments** on the same msgid. If `Paused (%s)` appears in three places, all three must agree on what the placeholder means; gettext merges identical msgids.
- **Concat of translatable strings** like `__('Hello ') . __(' world')`, **dynamic text domains** like `__($string, $variable)`, and other hard-to-translate patterns.

Plugin Check (the wp.org-side validator) catches a partly overlapping but distinct subset, so both run on every PR.

## Plugin Check

CI step in [`pr-checks.yml`](../.github/workflows/pr-checks.yml#L60). Runs the [official WordPress Plugin Check](https://github.com/WordPress/plugin-check-action) action with all categories enabled (`plugin_repo`, `security`, `performance`, `accessibility`, `general`) plus experimental checks. Some codes are explicitly ignored (`hidden_files`, `github_directory`, `unexpected_markdown_file`, `stable_tag_mismatch`) because they false-positive on the GitHub-flat repo layout or on the `-dev` suffix workflow.

If you ever submit a new version of the plugin to wp.org, the same checks run there. CI catches them earlier so a wp.org reviewer never has to.

## Running everything at once

```bash
make check     # lint + stan + psalm + tests
make release   # make check + version-alignment dry-run
```

`make release` is what you should run before pushing a release tag — it's the closest you can get to "what CI will say" without actually pushing.
