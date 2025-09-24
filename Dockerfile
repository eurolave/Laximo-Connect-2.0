FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libxml2-dev git unzip \
 && docker-php-ext-install soap \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencies
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction

# App
COPY . .

# В Railway порт приходит через $PORT
ENV PORT=8081
CMD ["sh","-lc","php -S 0.0.0.0:${PORT} -t public"]
