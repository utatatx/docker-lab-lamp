FROM php:8.5-apache

# Extensiones PHP: conexión a MySQL
# NOTA: desde PHP 8.5, OPcache viene integrada en el binario de PHP
# y ya NO se instala con docker-php-ext-install (es no-opcional).
RUN docker-php-ext-install pdo_mysql mysqli

# Activar mod_rewrite (URLs amigables, .htaccess)
RUN a2enmod rewrite headers

# Ajustes de la OPcache integrada (solo directivas, sin zend_extension=)
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Límites PHP personalizables
RUN { \
      echo 'upload_max_filesize=64M'; \
      echo 'post_max_size=64M'; \
      echo 'memory_limit=256M'; \
      echo 'date.timezone=Europe/Madrid'; \
    } > /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html
