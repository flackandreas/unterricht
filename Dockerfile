FROM php:8.2-apache

# Install dependencies, GD (with JPEG/WebP support) and EXIF extensions
RUN apt-get update && apt-get install -y \
    unzip \
    zip \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql gd exif

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Enable apache mod_rewrite
RUN a2enmod rewrite

# Change DocumentRoot to /var/www/html/public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure custom php.ini settings for larger file uploads
RUN echo "upload_max_filesize = 40M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 45M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini
