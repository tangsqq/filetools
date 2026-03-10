# 1. Use PHP 8.2 + Apache as the base
FROM php:8.2-apache

# 2. Configure Debian sources and install system dependencies
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

# Refresh font cache
RUN fc-cache -f -v

# 3. Install PHP Extensions
RUN pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-install zip

# 4. PHP Performance Configuration
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

# 7. 安装 Composer 二进制文件
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 8. 权限准备
RUN mkdir -p /var/www/.config /var/www/.cache /var/www/html/temp_uploads && \
    chown -R www-data:www-data /var/www/ /var/www/html/ && \
    chmod -R 777 /tmp/

ENV HOME=/var/www

# 9. 部署代码并安装依赖 (关键步骤)
# 先只复制 composer 相关文件以利用 Docker 缓存
COPY composer.json ./
# 如果有 composer.lock 也建议复制: COPY composer.json composer.lock ./

# 执行安装
RUN composer install --no-interaction --optimize-autoloader --no-dev --ignore-platform-reqs

# 复制其余所有代码
COPY . /var/www/html/

# 再次统一权限
RUN chown -R www-data:www-data /var/www/html/

# 10. Apache Configuration
RUN a2enmod rewrite && \
    sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

EXPOSE 80

CMD ["apache2-foreground"]
