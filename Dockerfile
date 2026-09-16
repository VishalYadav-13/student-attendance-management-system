# SAMS - Student Attendance Management System
# Production Container Image (PHP 8.2 + Apache + PostgreSQL PDO)

FROM php:8.2-apache

# Install system dependencies and PostgreSQL / SQLite driver libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libsqlite3-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    ca-certificates \
    && docker-php-ext-install pdo pdo_pgsql pdo_sqlite bcmath zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite and mod_headers
RUN a2enmod rewrite headers

# Configure Apache DocumentRoot to point to backend/public
ENV APACHE_DOCUMENT_ROOT /var/www/html/backend/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure directory directives and AllowOverride All
RUN echo '<Directory /var/www/html/backend/public>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/sams.conf && a2enconf sams

# Set working directory
WORKDIR /var/www/html

# Copy repository files
COPY . /var/www/html

# Configure runtime directories and permissions
RUN mkdir -p /var/www/html/tmp/ratelimit \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/tmp

# Expose default HTTP port
EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]
