# 1. Use PHP 8.2 + Apache as the base
FROM php:8.2-apache

# 2. Configure Debian sources and install system dependencies (LibreOffice + Fonts)
RUN sed -i 's/main/main contrib/g' /etc/apt/sources.list.d/debian.sources || \
    sed -i 's/main/main contrib/g' /etc/apt/sources.list

RUN apt-get update && \
    echo "ttf-mscorefonts-installer msttcorefonts/accepted-mscorefonts-eula select true" | debconf-set-selections && \
    apt-get install -y \
    libmagickwand-dev \
    libzip-dev \
    ghostscript \
    libreoffice \
    procps \
    unzip \
    git \
    fonts-wqy-zenhei \
    ttf-mscorefonts-installer \
    fonts-liberation \
    fontconfig \
    --no-install-recommends && \
    rm -rf /var/lib/apt/lists/*

# Refresh font cache to ensure Excel converts with correct characters
RUN fc-cache -f -v

# 3. Install PHP Extensions
RUN pecl install imagick && docker-php-ext-enable imagick && docker-php-ext-install zip

# 4. PHP Performance Configuration
RUN { \
    echo 'upload_max_filesize = 100M'; \
    echo 'post_max_size = 110M'; \
    echo 'memory_limit = 1024M'; \
    echo 'max_execution_time = 300'; \
    } > /usr/local/etc/php/conf.d/docker-php-custom.ini

# 5. Lift ImageMagick PDF restrictions
RUN find /etc/ImageMagick* -name "policy.xml" -exec sed -i 's/rights="none" pattern="PDF"/rights="read|write" pattern="PDF"/' {} +

# 6. Set Working Directory
WORKDIR /var/www/html

# 7. Install Composer binary
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 8. Permission Setup: Create config directories for LibreOffice headless mode
RUN mkdir -p /var/www/.config /var/www/.cache && \
    chown -R www-data:www-data /var/www/

ENV HOME=/var/www

# 9. Deploy code and install dependencies
COPY composer.json ./
# Uncomment if you have a lock file: COPY composer.lock ./

# Run install (This creates the 'vendor' folder inside the image)
RUN composer install --no-interaction --optimize-autoloader --no-dev --ignore-platform-reqs

# Copy the rest of the application
COPY . /var/www/html/

# Ensure web server user owns the files
RUN chown -R www-data:www-data /var/www/html/

# 10. Enable Apache mod_rewrite
RUN a2enmod rewrite && \
    sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

EXPOSE 80

CMD ["apache2-foreground"]
