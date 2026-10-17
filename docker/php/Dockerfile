FROM php:8.5-cli-alpine

# ffi lets OpenTelemetry bind its context to each Fiber: mcp/sdk runs every
# MCP request handler in one, and without it a traced call there warns
# (OTEL_PHP_FIBERS_ENABLED in compose.yml; see yii3-mcp-telemetry-bridge).
# pcov is installed but off (pcov.enabled=0): only `make mutation` switches it
# on, so every other target runs without a coverage driver's cost.
RUN apk add --no-cache postgresql-dev libffi-dev $PHPIZE_DEPS bash curl \
 && docker-php-ext-install pdo_pgsql ffi \
 && pecl install pcov \
 && docker-php-ext-enable pcov \
 && echo 'pcov.enabled=0' > /usr/local/etc/php/conf.d/zz-pcov.ini \
 && apk del postgresql-dev libffi-dev $PHPIZE_DEPS \
 && apk add --no-cache libpq libffi \
 && echo 'ffi.enable=true' > /usr/local/etc/php/conf.d/ffi.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /workspace/sandbox
