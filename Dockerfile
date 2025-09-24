FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libxml2-dev git unzip \ && docker-php-ext-install soap \ && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencies
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction

# App
COPY . .

EXPOSE 8081
CMD ["php", "-S", "0.0.0.0:8081", "-t", "public"]
