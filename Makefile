# diluxone-offload — developer task runner.
#
# All PHP-based commands run inside the official `composer:2` Docker
# image by default. That keeps the host clean of plugin-specific PHP
# extensions (dom, mbstring, xml, xmlwriter, etc.) which the standard
# WSL `php-cli` build tends to lack. The Composer + dev-tooling
# versions still come from composer.lock either way, so the runtime
# difference vs CI is just the PHP-extension surface area.
#
# Usage:
#   make            # = make help
#   make install    # composer install
#   make check      # everything CI runs
#   make release    # check + version-alignment dry-run
#
# Override DOCKER=0 to invoke local binaries instead. Only viable on
# hosts that already have a full PHP CLI with the required extensions
# (dom, mbstring, xml, xmlwriter, libxml, openssl, json, fileinfo,
# tokenizer) and `wp` and `npx` available on $PATH.

SHELL := /bin/bash

# -- Docker plumbing ---------------------------------------------------
# Mount the project read/write at /app, run as the host user so
# composer doesn't leave root-owned files in vendor/.
DOCKER ?= 1
DOCKER_USER := $(shell id -u):$(shell id -g)
DOCKER_RUN  := docker run --rm -u $(DOCKER_USER) -v $(CURDIR):/app -w /app

# Pin floating tags via env override for reproducibility:
#   make stan COMPOSER_IMAGE=composer:2.7
COMPOSER_IMAGE ?= composer:2
WP_CLI_IMAGE   ?= wordpress:cli
PHP_IMAGE      ?= php:8.3-cli

# `--network host` lets the wordpress:cli container reach the wp-env
# MySQL on the same host. It is unsupported on Docker Desktop for
# macOS/Windows; on those hosts override DOCKER_NET= to drop it (and
# arrange WP-CLI / integration tests another way, e.g. via wp-env).
DOCKER_NET ?= --network host

ifeq ($(DOCKER),1)
COMPOSER  := $(DOCKER_RUN) $(COMPOSER_IMAGE) composer
VENDOR    := $(DOCKER_RUN) $(COMPOSER_IMAGE)
PSALM_CMD := $(DOCKER_RUN) $(PHP_IMAGE) ./vendor/bin/psalm
WP_CLI    := $(DOCKER_RUN) $(DOCKER_NET) $(WP_CLI_IMAGE)
INTEG     := $(DOCKER_RUN) $(DOCKER_NET) $(COMPOSER_IMAGE)
else
COMPOSER  := composer
VENDOR    :=
PSALM_CMD := ./vendor/bin/psalm
WP_CLI    := wp
INTEG     :=
endif

# -- Default target ----------------------------------------------------
.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help.
	@awk 'BEGIN {FS = ":.*##"; printf "\nTargets:\n"} \
	  /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[1;32m%-18s\033[0m %s\n", $$1, $$2}' \
	  $(MAKEFILE_LIST)
	@echo
	@echo "Override the Docker mode with DOCKER=0 to use local binaries."

# -- Setup -------------------------------------------------------------
.PHONY: install
install: ## Install dev dependencies (composer install).
	$(COMPOSER) install --no-interaction --prefer-dist --no-progress

.PHONY: update
update: ## Update dev dependencies (composer update).
	$(COMPOSER) update --no-interaction --prefer-dist --no-progress

# -- Linting / static analysis ----------------------------------------
.PHONY: lint
lint: ## PHPCS + WordPress Coding Standards.
	# --no-cache a propósito: PHPCS cachea por archivo y, cuando un archivo
	# vuelve a un contenido que ya analizó, responde con el resultado viejo.
	# Eso da verde en local mientras el CI —que siempre arranca limpio— está
	# en rojo, y se pierde media hora buscando la diferencia.
	$(VENDOR) ./vendor/bin/phpcs --no-cache

.PHONY: lint-fix
lint-fix: ## Auto-fix PHPCS violations where possible.
	$(VENDOR) ./vendor/bin/phpcbf --no-cache

.PHONY: stan
stan: ## PHPStan level 8 (no baseline).
	$(VENDOR) ./vendor/bin/phpstan analyse --memory-limit=2G --no-progress

.PHONY: psalm
psalm: ## Psalm taint analysis (XSS / SQLi / RCE).
	$(PSALM_CMD) --taint-analysis --no-cache --no-progress

.PHONY: i18n
i18n: ## Generate diluxone-offload.pot via WP-CLI.
	mkdir -p build
	$(WP_CLI) i18n make-pot . build/diluxone-offload.pot \
	    --slug=diluxone-offload \
	    --domain=diluxone-offload \
	    --exclude=tests,vendor,node_modules,.wordpress-org,assets,docs,build

# -- Tests -------------------------------------------------------------
.PHONY: test
test: test-unit ## Run the unit-test suite (default — fast, no WP needed).

.PHONY: test-unit
test-unit: ## Run only the unit-test suite (no WordPress runtime).
	$(VENDOR) ./vendor/bin/phpunit --testsuite unit

.PHONY: test-integration
test-integration: ## Run integration tests against the wp-env stack (must be `make env` first).
	$(INTEG) ./vendor/bin/phpunit --testsuite integration

# -- Distribution build ------------------------------------------------
# The repo directory is diluxone-offload-wordpress (GitHub), but the plugin
# folder wordpress.org receives must be named after the slug, diluxone-offload:
# WordPress derives the text domain check from the folder name. `make dist`
# materialises exactly what ships, under the right name, applying .distignore
# so the tree has no tests, tooling or repo metadata in it.
# wp-env mounts ONLY this built copy (see .wp-env.json), never the repo root:
# mounting both would load the plugin twice and fatal on redeclaration. So
# after editing source, re-run `make dist` to see it in the local site.
DIST_DIR := build/diluxone-offload

.PHONY: dist
dist: ## Build build/diluxone-offload/ — exactly what gets published.
	@mkdir -p "$(DIST_DIR)"
	@# --delete, never `rm -rf` the directory itself: wp-env bind-mounts it, and
	@# replacing the inode leaves the container looking at a mount that is gone.
	@rsync -a --delete --exclude-from=.distignore --exclude='build' ./ "$(DIST_DIR)/"
	@echo "✔ Built $(DIST_DIR) ($$(find "$(DIST_DIR)" -type f | wc -l) files)"

# -- Plugin Check (wordpress.org review gate) --------------------------
# This is the tool the plugin review team runs. PHPCS/WPCS overlaps with it
# but does not replace it: Plugin Check also enforces readme.txt structure,
# plugin headers, i18n and directory rules that WPCS knows nothing about.
#
# It runs against the built dist, not the working tree. That is the artefact
# the reviewer actually receives, so there is no exclusion list to drift out
# of sync with .distignore, and the folder name matches the slug.
.PHONY: plugin-check
plugin-check: dist ## Run wordpress.org's Plugin Check on the built dist (needs `make env` first).
	@npx @wordpress/env run cli wp plugin is-installed plugin-check >/dev/null 2>&1 \
	  || npx @wordpress/env run cli wp plugin install plugin-check --activate
	@npx @wordpress/env run cli wp plugin is-active plugin-check >/dev/null 2>&1 \
	  || npx @wordpress/env run cli wp plugin activate plugin-check
	npx @wordpress/env run cli wp plugin check diluxone-offload --format=table --severity=5

.PHONY: plugin-check-all
plugin-check-all: dist ## Plugin Check on the built dist, including warnings and notices.
	npx @wordpress/env run cli wp plugin check diluxone-offload --format=table

# -- Aggregate ---------------------------------------------------------
.PHONY: check
check: lint stan psalm test ## Run every quality gate CI runs (lint, stan, psalm, unit tests).
	@echo "✔ All checks passed."

# -- Local dev environment (wp-env) ------------------------------------
.PHONY: env env-up
env: env-up ## Alias of env-up.
env-up: dist ## Start the local wp-env Docker stack.
	npx @wordpress/env start

.PHONY: env-down
env-down: ## Stop the local wp-env Docker stack.
	npx @wordpress/env stop

.PHONY: env-clean
env-clean: ## Destroy the local wp-env Docker stack and its volumes.
	npx @wordpress/env destroy

# -- Deploy / release --------------------------------------------------
# The plugin is developed here and tried on a real site. `make deploy-test`
# copies the working tree into that site's plugins directory — only what
# ships, so no vendor/, no tests, no tooling — and leaves the site's own
# files alone. Override SITE= to try it somewhere else.
SITE ?= $(HOME)/repos/cst-website
SITE_PLUGIN := $(SITE)/wp-content/plugins/diluxone-offload

.PHONY: deploy-test
deploy-test: ## Copy the working tree into a real site for manual smoke-testing.
	@if [ ! -d "$(SITE)/wp-content/plugins" ]; then \
	  echo "no site at $(SITE). Override with SITE=/path/to/wordpress"; \
	  exit 1; \
	fi
	@mkdir -p "$(SITE_PLUGIN)"
	rsync -a --delete \
	  --exclude-from=.distignore \
	  --exclude='.git' \
	  ./ "$(SITE_PLUGIN)/"
	@echo "✔ Copied to $(SITE_PLUGIN)"

.PHONY: release
release: check ## Pre-release validation: full quality gate + version-alignment dry-run.
	@echo "── version alignment check ─────────────────────────────"
	@PHP_VERSION=$$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' diluxone-offload.php | head -1 | sed -E 's/.*Version:[[:space:]]*//'); \
	 STABLE_TAG=$$(grep -E '^Stable tag:' readme.txt | sed -E 's/Stable tag:[[:space:]]*//'); \
	 PHP_BASE=$$(echo $$PHP_VERSION | sed -E 's/-(dev|alpha|beta|rc).*$$//'); \
	 echo "  PHP header Version : $$PHP_VERSION"; \
	 echo "  PHP base (no -dev) : $$PHP_BASE"; \
	 echo "  readme Stable tag  : $$STABLE_TAG"; \
	 if [ "$$PHP_BASE" = "$$STABLE_TAG" ]; then \
	   echo "  → match ✔"; \
	 else \
	   echo "  → MISMATCH ✗ (PHP base must equal readme Stable tag at tag time)"; exit 1; \
	 fi
	@echo "✔ Ready to tag."

# -- Cleanup -----------------------------------------------------------
.PHONY: clean
clean: ## Remove caches, build artefacts, and temporary files.
	rm -rf build .phpunit.result.cache .phpunit.cache .phpcs-cache .phpstan .psalm
	@echo "✔ Cleaned."
