# Imagen de la Mesa de Partes Virtual (PHP 8.3) para el entorno local
# (`docker compose up -d --build`) y para el despliegue en Railway, que construye
# este Dockerfile de la raíz.
#
# El servidor web es el embebido de PHP (`php -S` con docker/router.php). No hay
# Apache ni nginx: la barrera de seguridad (bloquear includes/, tests/, .env...)
# la aplica el router, en PHP. Menos piezas, menos configuración.
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
#   sodium             -> scrypt acelerado. Opcional: scrypt.php trae una
#                         implementación en PHP puro y funciona sin esta.
FROM php:8.3-cli

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

# El código envía cabeceras CSP propias y devuelve JSON; se fuerza UTF-8 para no
# depender de la configuración regional de la imagen base. sessions fuera del
# docroot, errores a stderr y límites holgados para los adjuntos.
COPY docker/php-dev.ini /usr/local/etc/php/conf.d/zz-app.ini

# El router va fuera del docroot para que no se pueda pedir por URL.
COPY docker/router.php /usr/local/bin/router.php

# El código va en el docroot, pero la app no separa público de privado: no hay
# un directorio public/. El router se encarga de cerrar includes/, docs/, tests/
# y los archivos sueltos. Es la única barrera, así que es obligatoria.
WORKDIR /var/www/html

# El servidor embebido es monohilo por defecto; con workers se atienden varias
# peticiones a la vez. El puerto real lo decide el entrypoint con $PORT.
ENV PHP_CLI_SERVER_WORKERS=8

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
     afiliacion.php mis-solicitudes.php upload-url.php subsanacion.php \
     guardar-subsanacion.php .env.example /var/www/html/

# Los .php se leen en cada request, así que la propiedad es de www-data.
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/router.php \
    && chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/www/storage/keys \
    && chown -R www-data:www-data /var/www/storage

ENTRYPOINT ["entrypoint"]
# sh -c para expandir $PORT en runtime: Railway lo inyecta (8080), y si no está
# (docker compose) cae al 80, que es el puerto que publica el compose.
CMD ["sh", "-c", "exec php -S 0.0.0.0:${PORT:-80} -t /var/www/html /usr/local/bin/router.php"]