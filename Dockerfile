# Imagen de la Mesa de Partes Virtual para desarrollo y pruebas locales.
#
# La app es PHP sin framework ni Composer: no hay `composer install` que hacer.
# Lo que sí hay que resolver es el conjunto de extensiones que el código usa de
# verdad, que no es el de un proyecto con dependencias declaradas.
#
#   pdo_pgsql / pgsql  -> Database.php. El repo usa PDO cuando está disponible
#                         y cae a la extensión nativa si no, así que se
#                         instalan las dos y la elección la hace el código.
#   curl               -> S3Service y AmspApiClient firman peticiones a mano
#                         con SigV4, sin AWS SDK, sobre curl.
#   mbstring           -> mb_strlen / mb_substr en el recorte de textos de los
#                         acuses y las vistas.
#   sodium             -> scryptaccelerado. Opcional: scrypt.php trae una
#                         implementación en PHP puro y funciona sin esta.
FROM php:8.3-apache

# libpq-dev  -> pdo_pgsql, pgsql
# libcurl4-openssl-dev -> curl
# libonig-dev -> mbstring
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpq-dev \
        libcurl4-openssl-dev \
        libonig-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql curl mbstring; \
    rm -rf /var/lib/apt/lists/*

# opcache acelera cada request, pero se deja la revalidación corta para que una
# edición de código se vea en el siguiente refresh sin reconstruir.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.validate_timestamps=1'; \
    } > /usr/local/etc/php/conf.d/opcache-dev.ini

# El código envía cabeceras CSP propias y devuelve JSON; se fuerza UTF-8 para no
# depender de la configuración regional de la imagen base.
COPY docker/php-dev.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# El código va en el docroot, pero la app no separa público de privado: no hay
# un directorio public/. El vhost se encarga de cerrar includes/, docs/, tests/
# y los archivos sueltos. Es la única barrera, así que es obligatoria.
WORKDIR /var/www/html

# Primero los archivos que casi no cambian, para que la capa quede cacheada y
# reconstruir tras editar un .php no reinstale las extensiones.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY bin/ /var/www/html/bin/
COPY schema.sql /var/www/html/schema.sql
COPY includes/ /var/www/html/includes/
COPY assets/ /var/www/html/assets/
COPY tests/ /var/www/html/tests/
COPY admin/ /var/www/html/admin/
COPY mpv/ /var/www/html/mpv/
COPY index.php login.php seguimiento.php guardar.php acuse.php \
     afiliacion.php mis-solicitudes.php upload-url.php .env.example /var/www/html/

# Los .php se leen en cada request, así que la propiedad es de www-data.
RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/www/storage/keys \
    && chown -R www-data:www-data /var/www/storage

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
