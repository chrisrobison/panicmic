# NextUp developer workflow
#
# Zero runtime dependencies; dev tools (PHPUnit, PHPStan) ship as PHARs
# downloaded into ./tools/ by `make tools`.

PHP            ?= php
TOOLS_DIR      := tools
PHPUNIT        := $(TOOLS_DIR)/phpunit.phar
PHPSTAN        := $(TOOLS_DIR)/phpstan.phar

PHPUNIT_URL    := https://phar.phpunit.de/phpunit-11.phar
PHPSTAN_URL    := https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar

PHP_SOURCES    := $(shell find src public scripts -type f -name '*.php' 2>/dev/null)

.PHONY: help tools lint stan test check clean-tools

help:
	@echo 'Targets:'
	@echo '  make tools   download phpunit.phar + phpstan.phar into ./tools/'
	@echo '  make lint    php -l across src/, public/, scripts/'
	@echo '  make stan    static analysis via phpstan'
	@echo '  make test    run phpunit'
	@echo '  make check   lint + stan + test (CI runs this)'
	@echo '  make clean-tools  remove ./tools/'

tools: $(PHPUNIT) $(PHPSTAN)

$(PHPUNIT):
	@mkdir -p $(TOOLS_DIR)
	@echo '→ downloading phpunit'
	@curl -fsSL -o $@ $(PHPUNIT_URL)
	@chmod +x $@
	@$(PHP) $@ --version

$(PHPSTAN):
	@mkdir -p $(TOOLS_DIR)
	@echo '→ downloading phpstan'
	@curl -fsSL -o $@ $(PHPSTAN_URL)
	@chmod +x $@
	@$(PHP) $@ --version

lint:
	@echo '→ php -l ($(words $(PHP_SOURCES)) files)'
	@set -e; for f in $(PHP_SOURCES); do $(PHP) -l "$$f" >/dev/null; done
	@echo '✓ lint clean'

stan: $(PHPSTAN)
	@$(PHP) $(PHPSTAN) analyse --memory-limit=512M

test: $(PHPUNIT)
	@$(PHP) $(PHPUNIT)

check: lint stan test

clean-tools:
	@rm -rf $(TOOLS_DIR)
