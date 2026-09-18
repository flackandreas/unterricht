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

# Produktionsvorgaben von PHP aktivieren.
#
# Das Abbild legt php.ini-production und php.ini-development nebeneinander,
# aktiviert aber keine von beiden. Ohne php.ini gelten die eingebauten
# Vorgaben - und dort ist display_errors eingeschaltet: jede Warnung landete
# samt absolutem Pfad im Browser. APP_ENV=development schaltet die Ausgabe in
# bootstrap.php wieder ein, fuer die Entwicklung.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Grenzen fuer Hausaufgabenfotos. Wichtig, dass sie hier stehen und nicht in
# der php.ini: conf.d wird danach geparst und gewinnt. Die php.ini-production
# setzt 2M/8M/128M - ohne diese Datei waere jeder Upload abgewiesen.
# Configure custom php.ini settings for larger file uploads
RUN echo "upload_max_filesize = 40M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 45M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

# Die PHP-Version gehoert nicht in jeden Antwort-Header: sie erspart die Suche
# danach, welche Luecken sich zu probieren lohnen. Ob die php.ini-production
# des Abbilds sie schon abschaltet, haengt von der Herkunft der Datei ab - die
# Fassung aus dem PHP-Quellpaket laesst expose_php an, die aus Debian nicht.
# Diese Zeile gilt in beiden Faellen.
RUN echo "expose_php = Off" > /usr/local/etc/php/conf.d/haerten.ini

# Die Ablage liegt bei einem bind-mount ausserhalb des Abbilds - die Rechte
# muessen deshalb beim Start gesetzt werden, nicht beim Bauen.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
