FROM php:8.3-cli-alpine
RUN apk add --no-cache libpq python3 \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps \
    && addgroup -g 1000 app && adduser -D -u 1000 -G app app
WORKDIR /app
COPY --chown=app:app . .
USER app
ENV APP_ENV=demo PHP_CLI_SERVER_WORKERS=4
CMD ["php", "bin/console", "help"]
