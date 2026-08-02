# PanicMic developer workflow
#
# Zero runtime dependencies; dev tools (PHPUnit, PHPStan) ship as PHARs
# downloaded into ./tools/ by `make tools`.

PHP            ?= php
TOOLS_DIR      := tools
PHPUNIT        := $(TOOLS_DIR)/phpunit.phar
PHPSTAN        := $(TOOLS_DIR)/phpstan.phar

# Pinned versions. Bumping these requires also updating the SHA256 below.
PHPUNIT_VERSION := 11.5.55
PHPSTAN_VERSION := 2.1.55

PHPUNIT_URL    := https://phar.phpunit.de/phpunit-$(PHPUNIT_VERSION).phar
PHPUNIT_ASC    := $(PHPUNIT_URL).asc
PHPSTAN_URL    := https://github.com/phpstan/phpstan/releases/download/$(PHPSTAN_VERSION)/phpstan.phar

# SHA256 verification. Pinned hashes live in tools/checksums.sha256
# (tracked in the repo). On download, the file's SHA256 must match the
# pinned line — otherwise the build fails. Refreshing requires deliberate
# update of both the version and the recorded hash.
CHECKSUM_FILE  := $(TOOLS_DIR)/checksums.sha256

PHP_SOURCES    := $(shell find src public scripts -type f -name '*.php' 2>/dev/null)

.PHONY: help tools lint stan test test-browser check clean-tools pin-checksums \
        migrate migrate-status migrate-dry-run migrate-check

help:
	@echo 'Targets:'
	@echo '  make tools          download + verify phpunit.phar + phpstan.phar'
	@echo '  make pin-checksums  recompute tools/checksums.sha256 after bumping versions'
	@echo '  make lint           php -l across src/, public/, scripts/'
	@echo '  make stan           static analysis via phpstan'
	@echo '  make test           run phpunit'
	@echo '  make test-browser   run Playwright browser smoke tests'
	@echo '  make check          lint + stan + test (CI runs this)'
	@echo '  make clean-tools    remove ./tools/'
	@echo ''
	@echo 'Database:'
	@echo '  make migrate-status  show applied/pending migrations for super + all tenants'
	@echo '  make migrate-dry-run preview pending migrations without applying'
	@echo '  make migrate         apply pending migrations to super + all tenants'
	@echo '  make migrate-check   exit non-zero if any migration is pending (deploy gate)'

tools: $(PHPUNIT) $(PHPSTAN)

# Verify SHA256 against the pinned hash in tools/checksums.sha256.
# On mismatch: delete the downloaded file and fail loudly.
define verify_sha256
	@if [ ! -f $(CHECKSUM_FILE) ]; then \
	  echo 'ERROR: $(CHECKSUM_FILE) is missing. Run `make pin-checksums` after auditing the new phar.'; \
	  rm -f $(1); exit 1; \
	fi
	@expected="$$(awk -v f='$(notdir $(1))' '$$2 == f {print $$1}' $(CHECKSUM_FILE))"; \
	if [ -z "$$expected" ]; then \
	  echo "ERROR: no pinned SHA256 for $(notdir $(1)) in $(CHECKSUM_FILE)"; \
	  rm -f $(1); exit 1; \
	fi; \
	actual="$$(shasum -a 256 $(1) | awk '{print $$1}')"; \
	if [ "$$actual" != "$$expected" ]; then \
	  echo "SHA256 mismatch for $(notdir $(1)):"; \
	  echo "  expected: $$expected"; \
	  echo "  got:      $$actual"; \
	  rm -f $(1); exit 1; \
	fi; \
	echo "  ✓ sha256 $$actual"
endef

$(PHPUNIT):
	@mkdir -p $(TOOLS_DIR)
	@echo '→ downloading phpunit $(PHPUNIT_VERSION)'
	@curl -fsSL -o $@ $(PHPUNIT_URL)
	@chmod +x $@
	$(call verify_sha256,$@)
	@# Best-effort GPG verification when gpg + signing key are available.
	@# Skipped silently in CI where the maintainer key isn't installed —
	@# SHA256 above is the authoritative gate.
	@if command -v gpg >/dev/null 2>&1; then \
	  curl -fsSL -o $@.asc $(PHPUNIT_ASC) 2>/dev/null || true; \
	  if [ -f $@.asc ]; then \
	    gpg --verify $@.asc $@ 2>/dev/null && echo '  ✓ gpg signature' || echo '  · gpg key not in keyring (skipped — sha256 already verified)'; \
	  fi; \
	fi
	@$(PHP) $@ --version

$(PHPSTAN):
	@mkdir -p $(TOOLS_DIR)
	@echo '→ downloading phpstan $(PHPSTAN_VERSION)'
	@curl -fsSL -o $@ $(PHPSTAN_URL)
	@chmod +x $@
	$(call verify_sha256,$@)
	@$(PHP) $@ --version

# Refresh tools/checksums.sha256 by re-downloading and recording hashes.
# Use only after auditing a new upstream release. Not run by `make tools`.
pin-checksums:
	@mkdir -p $(TOOLS_DIR)
	@echo '→ pinning checksums for phpunit $(PHPUNIT_VERSION) + phpstan $(PHPSTAN_VERSION)'
	@curl -fsSL -o $(PHPUNIT) $(PHPUNIT_URL)
	@curl -fsSL -o $(PHPSTAN) $(PHPSTAN_URL)
	@shasum -a 256 $(PHPUNIT) $(PHPSTAN) | awk '{print $$1"  "$$NF}' | sed 's|$(TOOLS_DIR)/||' > $(CHECKSUM_FILE)
	@echo '  wrote $(CHECKSUM_FILE):'
	@cat $(CHECKSUM_FILE)

lint:
	@echo '→ php -l ($(words $(PHP_SOURCES)) files)'
	@set -e; for f in $(PHP_SOURCES); do $(PHP) -l "$$f" >/dev/null; done
	@echo '✓ lint clean'

stan: $(PHPSTAN)
	@$(PHP) $(PHPSTAN) analyse --memory-limit=512M

test: $(PHPUNIT)
	@$(PHP) $(PHPUNIT)

test-browser:
	@npm run test:browser

check: lint stan test

# ---------------------------------------------------------------------------
# Database migrations
#
# Migrations are NOT applied automatically on deploy. TenantProvisioner only
# runs the full migration set for *newly created* tenants, so existing tenant
# databases drift silently as new files land. Migration 014 sat unapplied
# against three live tenants and broke every realtime write in the app.
#
# `make migrate-check` is the guard: run it in your deploy pipeline (and
# after pulling) so pending migrations fail loudly instead of at 9pm on a
# Friday in front of a room full of people.
# ---------------------------------------------------------------------------

migrate-status:
	@$(PHP) scripts/migrate.php status super
	@$(PHP) scripts/migrate.php status tenants

migrate-dry-run:
	@$(PHP) scripts/migrate.php super --dry-run
	@$(PHP) scripts/migrate.php tenants --dry-run

migrate:
	@$(PHP) scripts/migrate.php super
	@$(PHP) scripts/migrate.php tenants

migrate-check:
	@$(PHP) scripts/migrate.php check

clean-tools:
	@rm -rf $(TOOLS_DIR)
