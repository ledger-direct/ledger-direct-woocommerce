# syntax=docker/dockerfile:1.7
#
# LedgerDirect for WooCommerce — try-it image.
#
# WordPress and WooCommerce with the plugin from this checkout, installed the way the release build
# lays it out (Composer without dev packages, the files .distignore keeps), on the XRP Ledger
# testnet. The shop itself is installed on the first start, against the database of
# dev/try/compose.yaml; see docker/entrypoint.sh.
#
# WordPress (GPL-2.0-or-later) and WooCommerce (GPL-3.0-or-later) come unmodified from
# wordpress.org, pinned by version and checksum; their source ships inside this image. "WordPress"
# and "WooCommerce" are trademarks of their owners; this image is not an official image of either.
#
# Build from the repository root:
#   docker build -f dev/try/Dockerfile -t try-woocommerce .

ARG PHP_IMAGE=php:8.3.36-apache-bookworm
ARG COMPOSER_IMAGE=composer:2.11.1
ARG EXTENSION_INSTALLER=mlocati/php-extension-installer@sha256:35821db0661308f64aa18483bb3af56bedc3b40090ff441ed961327d1ccbceb8

FROM ${COMPOSER_IMAGE} AS composer
FROM ${EXTENSION_INSTALLER} AS extension-installer

FROM ${PHP_IMAGE} AS base
COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/
# The extensions WordPress and WooCommerce use, plus gmp for the wallet tool's signing
# (hardcastle/xrpl_php). DejaVu draws the demo article's picture.
RUN install-php-extensions bcmath exif gd gmp intl mysqli opcache zip \
 && apt-get update \
 && apt-get install -y --no-install-recommends fonts-dejavu-core unzip \
 && rm -rf /var/lib/apt/lists/*
COPY dev/try/docker/php.ini /usr/local/etc/php/conf.d/zz-ledger-direct-try.ini

FROM base AS build
ARG WORDPRESS_VERSION=7.1.4
ARG WORDPRESS_SHA1=b175879c4fb2d35300f9abe1153da9b543a35a2b
ARG WOOCOMMERCE_VERSION=11.2.1
ARG WOOCOMMERCE_SHA256=5317434cb381b5fdc7c1dd6ee258324f08c390976aaf8d1199f2a0e9a3ab8586
ARG WP_CLI_VERSION=2.12.0
ARG WP_CLI_SHA256=ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends rsync && rm -rf /var/lib/apt/lists/*
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/var/cache/composer
WORKDIR /opt/wordpress
RUN curl -fsSLo /tmp/wordpress.tar.gz "https://wordpress.org/wordpress-${WORDPRESS_VERSION}.tar.gz" \
 && echo "${WORDPRESS_SHA1}  /tmp/wordpress.tar.gz" | sha1sum -c - \
 && tar -xzf /tmp/wordpress.tar.gz --strip-components=1 -C /opt/wordpress \
 && curl -fsSLo /tmp/woocommerce.zip "https://downloads.wordpress.org/plugin/woocommerce.${WOOCOMMERCE_VERSION}.zip" \
 && echo "${WOOCOMMERCE_SHA256}  /tmp/woocommerce.zip" | sha256sum -c - \
 && unzip -q /tmp/woocommerce.zip -d /opt/wordpress/wp-content/plugins \
 && curl -fsSLo /usr/local/bin/wp "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" \
 && echo "${WP_CLI_SHA256}  /usr/local/bin/wp" | sha256sum -c - \
 && chmod 0755 /usr/local/bin/wp \
 && rm -rf /tmp/wordpress.tar.gz /tmp/woocommerce.zip \
    wp-content/plugins/akismet wp-content/plugins/hello.php wp-content/themes/twentytwentythree wp-content/themes/twentytwentyfour
# The plugin from this checkout: its locked runtime dependencies, and only the files .distignore
# lets into the WordPress.org release.
COPY . /opt/ledger-direct/plugin
RUN --mount=type=cache,target=/var/cache/composer \
    cd /opt/ledger-direct/plugin \
 && composer install --no-interaction --no-progress --no-dev --no-scripts --optimize-autoloader \
 && rsync -a --exclude-from=.distignore ./ /opt/wordpress/wp-content/plugins/ledger-direct/ \
 && rm -rf /opt/ledger-direct/plugin

# The shop's testnet wallet tool (hardcastle/xrpl_php), in a vendor/ of its own: it runs as a separate
# process, so its dependencies never meet the plugin's.
COPY dev/try/wallet/composer.json /opt/ledger-direct/wallet/composer.json
RUN --mount=type=cache,target=/var/cache/composer \
    composer install --no-interaction --no-progress --no-dev --working-dir=/opt/ledger-direct/wallet

FROM base AS runtime
ARG LD_VERSION=dev-try
ARG WORDPRESS_VERSION=7.1.4
ARG WOOCOMMERCE_VERSION=11.2.1
LABEL org.opencontainers.image.title="LedgerDirect for WooCommerce — try it" \
      org.opencontainers.image.description="WordPress and WooCommerce with the LedgerDirect XRPL payment plugin, on the XRP Ledger testnet. For trying the plugin, not for production." \
      org.opencontainers.image.source="https://github.com/ledger-direct/ledger-direct-woocommerce" \
      org.opencontainers.image.licenses="MIT AND GPL-2.0-or-later AND GPL-3.0-or-later" \
      org.opencontainers.image.version="${LD_VERSION}" \
      com.ledger-direct.wordpress.version="${WORDPRESS_VERSION}" \
      com.ledger-direct.woocommerce.version="${WOOCOMMERCE_VERSION}"
# Apache as www-data on 8080, WordPress's permalink rules in the server configuration (no .htaccess).
COPY dev/try/docker/apache.conf /etc/apache2/conf-available/ledger-direct-try.conf
RUN a2enmod rewrite expires \
 && a2enconf ledger-direct-try \
 && sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
 && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf
COPY --from=build /usr/local/bin/wp /usr/local/bin/wp
COPY --from=build /opt/wordpress /var/www/html
COPY --from=build /opt/ledger-direct/wallet /opt/ledger-direct/wallet
COPY dev/try/docker/wp-config.php /var/www/html/wp-config.php
COPY dev/try/docker/mu-plugin.php /var/www/html/wp-content/mu-plugins/ledger-direct-try.php
COPY dev/try/docker/entrypoint.sh dev/try/docker/install.php dev/try/docker/wallet.php /usr/local/share/ledger-direct/
# The miniatures (catalog.json and pictures; their licence is in dev/try/catalog/NOTICE.md).
COPY dev/try/catalog/catalog.json dev/try/catalog/NOTICE.md /usr/local/share/ledger-direct/catalog/
COPY dev/try/catalog/images /usr/local/share/ledger-direct/catalog/images
# The code belongs to root and is read-only for the web server; what the shop writes lives in two
# volumes: uploads (pictures, WooCommerce's logs) and the stack's state (keys, the testnet wallet,
# what has been set up) outside the document root.
# What identifies this build to an existing shop: WordPress, WooCommerce and the plugin's files. When
# it differs from the one the shop was set up with (an updated image on existing volumes), the
# entrypoint runs the database updates. The same for the catalogue and the setup that lays it out
# (install.php): a new one is imported into an existing shop on its next start.
RUN chmod 0755 /usr/local/share/ledger-direct/entrypoint.sh \
 && mkdir -p /var/www/html/wp-content/uploads /var/lib/ledger-direct-try \
 && chown www-data:www-data /var/www/html/wp-content/uploads /var/lib/ledger-direct-try \
 && chmod 0700 /var/lib/ledger-direct-try \
 && (cd /var/www/html && find wp-includes/version.php wp-content/plugins/woocommerce/woocommerce.php wp-content/plugins/ledger-direct \
      -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16) > /usr/local/share/ledger-direct/build-id \
 && (cd /usr/local/share/ledger-direct && cat install.php catalog/catalog.json catalog/images/* | sha256sum | cut -c1-16) \
      > /usr/local/share/ledger-direct/catalog-id
# WP-CLI as www-data: its cache in /tmp, no update checks (the image pins it).
ENV WP_CLI_CACHE_DIR=/tmp/wp-cli \
    WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1
USER www-data
WORKDIR /var/www/html
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=3 \
  CMD curl -fs -o /dev/null http://127.0.0.1:8080/wp-login.php || exit 1
ENTRYPOINT ["/usr/local/share/ledger-direct/entrypoint.sh"]
