.PHONY: build clean release release-dry-run clean-build

EXCLUDE_VENDOR_DIRS= test tests doc docs example examples bin

build:
	composer install --no-dev --no-scripts --optimize-autoloader
	git archive --prefix=ledger-direct/ --format=zip --output ledger-direct-`git rev-parse HEAD`.zip `git rev-parse --abbrev-ref HEAD`
	mkdir ledger-direct
	cp -r vendor ledger-direct/.
	find ledger-direct/vendor -type f ! \( -name "*.php" -o -name "*.json" -o -name "*.md" \) -delete
	for d in $(EXCLUDE_VENDOR_DIRS); do find ledger-direct/vendor -type d -name $$d -exec rm -rf {} +; done
	zip -r ledger-direct-`git rev-parse HEAD`.zip ledger-direct/vendor
	rm -rf ledger-direct

clean:
	rm -rf ledger-direct
	rm -f ledger-direct-*.zip

# ===== WordPress.org release workflow =====
# These targets allow releasing to the WP.org SVN using scripts/deploy-wp-org.sh

PLUGIN_SLUG ?= ledger-direct
PLUGIN_MAIN ?= ledger-direct.php
SVN_URL     ?= https://plugins.svn.wordpress.org/$(PLUGIN_SLUG)

# Optional override when calling: make release VERSION=0.10.2
VERSION ?=

clean-build:
	rm -rf .build

# Build and verify everything the release would ship, without touching SVN
release-dry-run: clean-build
	chmod +x scripts/deploy-wp-org.sh
	DRY_RUN=1 \
	PLUGIN_SLUG=$(PLUGIN_SLUG) \
	PLUGIN_MAIN=$(PLUGIN_MAIN) \
	VERSION=$(VERSION) \
	./scripts/deploy-wp-org.sh

release: clean-build
	chmod +x scripts/deploy-wp-org.sh
	PLUGIN_SLUG=$(PLUGIN_SLUG) \
	PLUGIN_MAIN=$(PLUGIN_MAIN) \
	SVN_URL=$(SVN_URL) \
	VERSION=$(VERSION) \
	./scripts/deploy-wp-org.sh