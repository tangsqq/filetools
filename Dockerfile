# 1. Use PHP 8.2 + Apache as the base
FROM php:8.2-apache

# 2. Configure Debian sources and install system dependencies
# Enable 'contrib' to allow installation of ttf-mscorefonts-installer (Microsoft fonts)
RUN sed -i 's/main/main contrib/g' /etc/apt/sources.list.d/debian.sources || \
    sed -i 's/main/main contrib/g' /etc/apt/sources.list

RUN apt-get update && \
    # Automatically accept Microsoft EULA for fonts
    echo "ttf-mscorefonts-installer msttcorefonts/accepted-mscorefonts-eula select true" | debconf-set-selections && \
    apt-get install -y \
    libmagickwand-dev \
    libzip-dev \
    ghostscript \
    libreoffice \
    procps \
    # Font support: Chinese (Zenhei) + Microsoft Fonts (Times New Roman, etc.)
    fonts-wqy-zenhei \
    ttf-mscorefonts-installer \
    fonts-liberation \
    fontconfig \
    --no-install-recommends && \
    rm -rf /var/lib/apt/lists/*

# Refresh font cache to recognize new fonts
RUN fc-cache -f -v

# 3. Install PHP Extensions (Imagick and Zip)
RUN pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-install zip

# 4. PHP Performance Configuration (Optimized for large document conversion)
RUN { \
    echo 'upload_max_filesize = 100M'; \
    echo 'post_max_size = 110M'; \
    echo 'memory_limit = 512M'; \
    echo 'max_execution_time = 300'; \
    } > /usr/local/etc/php/conf.d/docker-php-custom.ini

# 5. Lift ImageMagick PDF restrictions
RUN find /etc/ImageMagick* -name "policy.xml" -exec sed -i 's/rights="none" pattern="PDF"/rights="read|write" pattern="PDF"/' {} +

# 6. Set Working Directory
WORKDIR /var/www/html

# 7. [Critical Fix] Permissions and Environment Setup
# LibreOffice requires a writable HOME directory for config and cache
RUN mkdir -p /var/www/.config /var/www/.cache /var/www/html/temp_uploads && \
    chown -R www-data:www-data /var/www/ /var/www/html/ && \
    chmod -R 777 /tmp/

# Force LibreOffice to use /var/www as its home to avoid permission errors
ENV HOME=/var/www

# 8. Apache Configuration
COPY . /var/www/html/
RUN a2enmod rewrite && \
    sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

EXPOSE 80

CMD ["apache2-foreground"]
