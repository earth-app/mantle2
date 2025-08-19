FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    zip \
    unzip \
    postgresql-dev \
    redis \
    freetype-dev \
    libjpeg-turbo-dev \
    libwebp-dev

# Clear cache
RUN rm -rf /var/cache/apk/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    opcache

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install APCu
RUN pecl install apcu && docker-php-ext-enable apcu

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-custom.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini

# Create user for php processes
RUN addgroup -g 1000 -S www && \
    adduser -u 1000 -S www -G www

# Change ownership of working directory
RUN chown -R www:www /var/www/html

# Switch to non-privileged user
USER www

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]