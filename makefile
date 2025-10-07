.PHONY: build clean

build:
	composer install --no-dev --no-scripts --optimize-autoloader
	git archive --prefix=ledger-direct/ --format=zip --output ledger-direct-`git rev-parse HEAD`.zip `git rev-parse --abbrev-ref HEAD`
	mkdir ledger-direct-woocommerce
	cp -r vendor ledger-direct-woocommerce/.
	zip -r ledger-direct-`git rev-parse HEAD`.zip ledger-direct/vendor
	rm -rf ledger-direct

clean:
	rm -rf ledger-direct
	rm -f ledger-direct-*.zip