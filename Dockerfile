# Use PHP with Apache
FROM php:8.2-apache

# 1. Install System Dependencies & LibreOffice
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    libzip-dev \
    unzip \
    libreoffice \
    fonts-noto-cjk \
    && rm -rf /var/lib/apt/lists/*

# 2. Install PHP Extensions (Required for PhpSpreadsheet)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd zip bcmath

# 3. Enable Apache mod_rewrite
RUN a2enmod rewrite

# 4. Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 5. Set Working Directory
WORKDIR /var/www/html

# 6. Copy Application Source
COPY . .

# 7. Install PHP Dependencies
# Note: Ensure you have a composer.json file in your project
RUN composer install --no-interaction --optimize-autoloader

# 8. Create Temp Directory and Set Permissions
# Your code uses C:/Windows/Temp, we need to fix that or ensure /tmp works
RUN mkdir -p /var/www/html/temp_uploads && \
    chmod -R 777 /var/www/html/temp_uploads

# 9. Configure Apache to listen on Render's PORT
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Expose the port
EXPOSE 80
