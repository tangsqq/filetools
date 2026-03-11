# 使用 PHP 8.2 配合 Apache
FROM php:8.2-apache

# 1. 安装系统依赖：LibreOffice, Imagick, 字体以及压缩库
RUN apt-get update && apt-get install -y \
    libmagickwand-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    zip \
    unzip \
    libreoffice \
    fonts-noto-cjk \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*

# 2. 安装并启用 PHP 扩展
RUN pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd zip xml bcmath

# 3. 启用 Apache 重写模块
RUN a2enmod rewrite

# 4. 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 5. 设置工作目录
WORKDIR /var/www/html

# 6. 复制项目文件
COPY . .

# 7. 安装 PHP 依赖
RUN composer install --no-interaction --optimize-autoloader

# 8. 创建临时目录并开放最高权限（用于存放上传的文件和转换结果）
RUN mkdir -p /var/www/html/temp_uploads && \
    chmod -R 777 /var/www/html/temp_uploads

# 9. 自动适配 Render 的端口 (Render 会动态分配 $PORT)
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# 暴露端口
EXPOSE 80
