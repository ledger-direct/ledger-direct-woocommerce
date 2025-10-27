.PHONY: build clean

EXCLUDE_DIRS= test tests doc docs example examples bin

build:
	composer install --no-dev --no-scripts --optimize-autoloader
	git archive --prefix=ledger-direct/ --format=zip --output ledger-direct-`git rev-parse HEAD`.zip `git rev-parse --abbrev-ref HEAD`
	mkdir ledger-direct
	cp -r vendor ledger-direct/.
	for d in $(EXCLUDE_DIRS); do find ledger-direct/vendor -type d -name $$d -exec rm -rf {} +; done
	zip -r ledger-direct-`git rev-parse HEAD`.zip ledger-direct/vendor
	rm -rf ledger-direct

clean:
	rm -rf ledger-direct
	rm -f ledger-direct-*.zip