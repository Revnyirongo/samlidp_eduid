FROM php:7.3-cli-buster

# Install system dependencies
RUN sed -i 's|deb.debian.org/debian|archive.debian.org/debian|g' /etc/apt/sources.list \
    && sed -i 's|security.debian.org/debian-security|archive.debian.org/debian-security|g' /etc/apt/sources.list \
    && printf 'Acquire::Check-Valid-Until "0";\nAcquire::Check-Date "0";\n' > /etc/apt/apt.conf.d/99archive \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
       git \
       unzip \
       libpq-dev \
       libzip-dev \
       libicu-dev \
       libxml2-dev \
       postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Symfony/Doctrine
RUN docker-php-ext-install -j"$(nproc)" intl pdo_pgsql

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . /app

RUN mkdir -p app/var/cache app/var/logs certs \
    && groupadd --gid 1000 app \
    && useradd --uid 1000 --gid app --shell /bin/bash --create-home app \
    && chown -R app:app /app

USER app

ENV APP_ENV=dev \
    APP_DEBUG=1 \
    APP_HOST=0.0.0.0 \
    APP_PORT=8080

EXPOSE 8080

WORKDIR /app/app

ENTRYPOINT ["/app/scripts/entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "web", "web/app.php"]
