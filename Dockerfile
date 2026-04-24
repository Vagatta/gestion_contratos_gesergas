FROM php:8.2-apache

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    curl \
    git \
    wait-for-it \
    && rm -rf /var/lib/apt/lists/*

# Instalar extensiones PHP necesarias
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mysqli \
    gd \
    zip \
    exif \
    mbstring

# Habilitar mod_rewrite de Apache
RUN a2enmod rewrite

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar Apache para apuntar a /var/www/html/public
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf
RUN sed -i 's!/var/www!/var/www/html/public!g' /etc/apache2/apache2.conf

# Copiar configuración personalizada de Apache
COPY docker/apache.conf /etc/apache2/conf-available/app.conf
RUN a2enconf app

# Directorio de trabajo
WORKDIR /var/www/html

# Copiar composer.json primero (para cache de capas Docker)
COPY composer.json composer.lock* ./
RUN composer install --no-scripts --no-autoloader --no-dev

# Copiar el resto de la aplicación
COPY . .

# Generar autoloader optimizado
RUN composer dump-autoload --optimize

# Crear directorio uploads con permisos
RUN mkdir -p /var/www/html/uploads/contracts \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 775 /var/www/html/uploads

# Script de entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
