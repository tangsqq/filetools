FROM php:8.2-apache

# 1. Install system dependencies
RUN apt-get update && apt-get install -y \
    libmagickwand-dev \
    libzip-dev \
    libreoffice \
    unzip \
    git \
    fonts-wqy-zenhei \
    --no-install-recommends && \
    rm -rf /var/lib/apt/lists/*

# 2. Install PHP extensions (Required for your composer.json)
RUN pecl install imagick && docker-php-ext-enable imagick
RUN docker-php-ext-install zip

# 3. Setup Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1

# 4. Copy and Install Dependencies
# This will now work because the JSON is valid
COPY composer.json ./
RUN composer install --no-interaction --optimize-autoloader --no-dev

# 5. Copy Source Code
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html/

EXPOSE 80
CMD ["apache2-foreground"]
