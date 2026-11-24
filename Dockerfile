FROM php:8.2-apache

# Enable Apache modules
RUN a2enmod rewrite headers

# Install necessary PHP extensions
RUN docker-php-ext-install session pdo pdo_mysql

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set file permissions for persistent JSON storage and sessions
RUN mkdir -p /var/www/html/storage/sessions && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/storage

# Support dynamic port binding for Render / Railway / Heroku ($PORT)
RUN echo '#!/bin/bash\n\
PORT="${PORT:-80}"\n\
sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf\n\
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/000-default.conf\n\
exec apache2-foreground' > /usr/local/bin/entrypoint.sh && \
    chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80 8080

CMD ["/usr/local/bin/entrypoint.sh"]
