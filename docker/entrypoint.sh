#!/bin/sh
# Arranque del contenedor: espera a PostgreSQL, aplica el esquema y deja las
# llaves de cifrado en un estado estable.
set -e

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-5432}"

# A stderr, nunca a stdout: varias de estas funciones se invocan dentro de una
# sustitución $(...) para devolver un valor, y si el log saliera por stdout se
# metería dentro de la variable. Así se rompió la llave de cifrado una vez.
log() { printf '[entrada] %s\n' "$*" >&2; }

# ---------------------------------------------------------------------------
# 1. Esperar a la base de datos
# ---------------------------------------------------------------------------
# Se prueba el puerto con fsockopen en vez de instalar pg_isready o netcat: la
# imagen es de PHP, y para un chequeo de TCP no vale la pena agregar paquetes.
# El orden importa: el esquema se aplica una vez, así que correr contra una base
# a medio levantar daría un error que parece de la app y no del arranque.
log "esperando postgres en ${DB_HOST}:${DB_PORT}..."
intentos=0
until php -r '
    $h = getenv("DB_HOST") ?: "db";
    $p = (int) (getenv("DB_PORT") ?: 5432);
    $s = @fsockopen($h, $p, $errno, $errstr, 2);
    exit($s ? 0 : 1);
'; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 60 ]; then
        log "postgres no respondió en 120 s. Revisa 'docker compose logs db'."
        exit 1
    fi
    sleep 2
done
log "postgres responde"

# ---------------------------------------------------------------------------
# 2. Llaves de cifrado
# ---------------------------------------------------------------------------
# APP_DATA_KEY cifra los datos personales de los asociados y JWT_SECRET firma
# los acuses. Si se regeneran en cada arranque, lo ya cifrado deja de descifrarse
# y los acuses dejan de validar. Por eso se guardan en un volumen: se generan
# una vez y se reutilizan.
#
# No se toman del .env del host a propósito. El Compose las deja fuera a
# propósito: las claves de producción no deberían cifrar datos de prueba, y al
# revés tampoco.
KEYS_DIR=/var/www/storage/keys
mkdir -p "$KEYS_DIR"

generar_llave() {
    nombre="$1"
    archivo="$KEYS_DIR/$nombre"

    if [ -s "$archivo" ]; then
        cat "$archivo"
        return
    fi

    # Se escribe una sola vez en un temporal y luego se renombra: si el
    # contenedor muere a mitad, no queda un archivo truncado que se lea como
    # llave. El valor se devuelve leyendo el archivo ya renombrado, para que lo
    # exportado y lo guardado sean exactamente el mismo.
    tmp="$archivo.tmp"
    php -r 'echo bin2hex(random_bytes(32));' > "$tmp"
    mv "$tmp" "$archivo"
    chown www-data:www-data "$archivo" 2>/dev/null || true
    chmod 600 "$archivo"
    log "llave $nombre generada"
    cat "$archivo"
}

if [ -z "${APP_DATA_KEY:-}" ]; then
    APP_DATA_KEY="$(generar_llave app_data_key)"
    export APP_DATA_KEY
fi

if [ -z "${JWT_SECRET:-}" ]; then
    JWT_SECRET="$(generar_llave jwt_secret)"
    export JWT_SECRET
fi

# Las claves también tienen que estar en el entorno de Apache, que no hereda del
# entrypoint: por eso se pasan por el proceso en vez de quedarse solo en la shell.
export APP_DATA_KEY JWT_SECRET

# `docker compose exec` abre una shell nueva que no hereda nada de este script, así
# que un `php bin/migrate.php` a mano se quedaría sin llaves. Se escribe un .env
# con las dos, que es donde config.php las busca cuando getenv() no las trae.
#
# Queda en el docroot, pero Apache lo bloquea: GET /.env responde 403, igual que
# en cualquier despliegue. Además queda en 640 root:www-data, que es el único
# usuario que necesita leerlo.
ENV_FILE=/var/www/html/.env
umask 0027
cat > "$ENV_FILE" <<EOF
# Generado por docker/entrypoint.sh en cada arranque.
# No editar a mano: se sobrescribe.
# Solo contiene las llaves que genera el contenedor. AWS, base de datos y los
# datos institucionales llegan como variables de entorno de docker-compose.
APP_DATA_KEY=$APP_DATA_KEY
JWT_SECRET=$JWT_SECRET
EOF
chown root:www-data "$ENV_FILE"
chmod 640 "$ENV_FILE"

# ---------------------------------------------------------------------------
# 3. Esquema
# ---------------------------------------------------------------------------
# Setup::ensureDatabase() es idempotente (todo es IF NOT EXISTS), así que se
# puede correr en cada arranque.
log "aplicando esquema..."
if php /var/www/html/bin/migrate.php; then
    log "esquema listo"
else
    log "FALLO al aplicar el esquema. Revisa 'docker compose logs app'."
    exit 1
fi

# ---------------------------------------------------------------------------
# 4. Avisos de configuración incompleta
# ---------------------------------------------------------------------------
# No se aborta: el portal y el panel se pueden revisar sin S3. Lo que no
# funciona sin bucket es subir adjuntos.
if [ -z "${AWS_ACCESS_KEY_ID:-}" ] || [ -z "${AWS_SECRET_ACCESS_KEY:-}" ] || [ -z "${S3_BUCKET_NAME:-}" ]; then
    log "AVISO: faltan AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / S3_BUCKET_NAME."
    log "       El portal abre, pero subir adjuntos fallará hasta definirlas en .env."
fi

# ---------------------------------------------------------------------------
# 5. Apache
# ---------------------------------------------------------------------------
mkdir -p /var/www/storage/sessions
chown -R www-data:www-data /var/www/storage

log "iniciando: $*"
exec "$@"
