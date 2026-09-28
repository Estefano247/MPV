# Portal del Asociado — AMSP (`/solicitudes`)

Portal web PHP auto-contenido que permite a un asociado consultar su
**estado de cuenta de préstamo** y presentar los **procesos de afiliación,
crédito, pre-evaluación y trámites generales a través de la Mesa de Partes
Virtual (MPV)** (formulario unificado).

Consume la API pública de AMSP (`https://amspweb.net/api`) y guarda los
documentos adjuntos en **AWS S3** (con cifrado en reposo SSE-AES-256).

---

## Mesa de Partes Virtual (MPV)

La plataforma funciona como Mesa de Partes Virtual de la entidad e incorpora:

1. **Directiva de creación** — `docs/directiva.md` (Directiva Nº `MPV_NUMERO`).
2. **Responsable designado** — configurable en el `.env` (`MPV_RESPONSABLE`,
   `MPV_RESPONSABLE_NOMBRE`).
3. **Plataforma web con formulario y carga de archivos** — `mpv/index.php` unifica
   los **cuatro trámites** (documento general, afiliación, crédito y
   pre-evaluación) en un solo formulario con selector de tipo; la pre-evaluación
   valida los 4 adjuntos requeridos y al concluir invita a continuar con crédito.
4. **Registro con número de expediente o cargo** — cada presentación recibe un
   `Nº de cargo` (`C-AAAA-NNNNNN`) y un `Nº de expediente` (`E-AAAA-NNNNNN`)
   correlativos anuales (tabla `correlativos`).
5. **Acuse de recibo automático y descargable** — `acuse.php` genera el acuse en
   HTML y en **PDF descargable**, con huella de verificación (HMAC-SHA256).
6. **Flujo de derivación y seguimiento** — áreas (`areas`), movimientos
   (`movimientos`) y seguimiento público (`seguimiento.php`) o interno
   (`admin/`).
7. **Observación y subsanación** — el área registra una observación
   (`observaciones`) y el expediente queda `observado` con un plazo para
   responder; el remitente la subsana en `subsanacion.php`, recibe su propio
   `Nº de cargo` (`S-AAAA-NNNNNN`) y acuse, y el área acepta o rechaza lo
   presentado (`subsanaciones`).
8. **Seguridad** — cifrado en tránsito (HTTPS), cifrado en reposo de documentos
   (SSE-AES-256 en S3) y de datos personales (AES-256-GCM vía `DataProtector`,
   clave `APP_DATA_KEY`), roles (`admin`, `empleado`, `super-admin`), bitácora de
   auditoría (`audit_log`) y respaldos.
9. **Política de privacidad** — `docs/politica-de-privacidad.md` (Ley 29733).
10. **Manual de procedimientos y capacitación** — `docs/manual-de-procedimientos.md`.
11. **Soporte, contingencia y publicación en web** — `docs/soporte-y-contingencia.md`.

---

## Requisitos

- PHP ≥ 8.1 con extensiones: `pdo_pgsql`, `pgsql`, `curl`, `openssl`, `mbstring`
- Servidor web (Apache/nginx) o el servidor embebido de PHP
- PostgreSQL (solo para grafos de solicitudes; configuración en `DATABASE_URL`)

O bien, nada de lo anterior: **Docker Desktop** y `docker compose up -d --build`
(ver [Puesta en marcha con Docker](#puesta-en-marcha-con-docker-recomendado)).

---

## Configuración

1. Copia `.env.example` a `.env` **o** usa el `.env` existente (ver `.env`).
2. Completa los valores (nunca comitees el `.env` real):

| Variable | Descripción |
|---|---|
| `AWS_REGION` | Región de AWS (ej. `sa-east-1`) |
| `AWS_ACCESS_KEY_ID` | Access Key de AWS |
| `AWS_SECRET_ACCESS_KEY` | Secret Key de AWS |
| `S3_BUCKET_NAME` | Bucket S3 para los documentos |
| `JWT_SECRET` | Secreto para firmar tokens (mín. 32 caracteres) |
| `APP_DATA_KEY` | Clave de cifrado de datos personales (hex de 32 bytes, AES-256-GCM) |
| `DATABASE_URL` | DSN de PostgreSQL, ej. `postgresql://user:pass@localhost:5432/db` |
| `AMSP_API_BASE` | (Opcional) Base de la API AMSP. Por defecto `https://amspweb.net/api` |
| `PUBLIC_URL` | (Opcional) URL pública del portal. Por defecto `http://localhost:8080` |
| `MPV_*` | Datos institucionales de la MPV (número, responsable, correo, horario...) |
| `MPV_PLAZO_OBSERVACION_DIAS` | Días de plazo para subsanar una observación. Por defecto 10, acotado a 5-90 |

El esquema de la base se encuentra en `schema.sql` (único, con todas las tablas:
`solicitudes`, MPV, panel y auditoría) y se **crea/corrige solo** al guardar una
solicitud o al consultar las páginas MPV.

> El **CORS del bucket S3** se configura manualmente en AWS y no se toca.

---

## Puesta en marcha con Docker (recomendado)

Levanta PHP 8.3 + Apache y PostgreSQL 16 sin instalar nada en el sistema. La
única cosa que tienes que tener lista es el `.env` con las credenciales de AWS,
que ya existe en este proyecto.

```bash
docker compose up -d --build
```

Abrir: **http://localhost:8080**

`docker-compose.yml` lee el `.env` del host automáticamente, así que las
credenciales de AWS **no se copian dentro de la imagen** (`.dockerignore` lo
impide). Solo se inyectan como variables de entorno en tiempo de ejecución.

### Qué hace el entrypoint

`docker/entrypoint.sh`, en orden:

1. Espera a que PostgreSQL acepte conexiones.
2. Genera `APP_DATA_KEY` y `JWT_SECRET` si no existen, y los guarda en el volumen
   `appdata`. Son 64 hexadecimales cada uno.
3. Los escribe en `/var/www/html/.env` en modo `640 root:www-data`, para que
   también los vean los scripts CLI (`docker compose exec app php ...`).
4. Aplica el esquema y crea el usuario admin si hay `SEED_ADMIN_PASSWORD`.
5. Arranca Apache.

> **No uses `docker compose down -v`.** El flag `-v` borra el volumen `appdata` y
> con él las llaves de cifrado. Todo lo cifrado con `APP_DATA_KEY` deja de poder
> descifrarse. Para reiniciar limpio se usa `docker compose down` a secas.

El `.env` que escribe el contenedor queda en el docroot, pero Apache lo bloquea:
`GET /.env` responde **403**, igual que `includes/`, `tests/`, `bin/`,
`schema.sql` y `admin/AUDITORIA.md`. No es un `.env` de desarrollo, es un
`.env` generado en cada arranque y controlado por permisos.

### Comandos útiles

```bash
docker compose exec app php bin/check-env.php   # extensiones y variables
docker compose exec app php tests/run.php       # 270 pruebas
docker compose exec app php bin/migrate.php     # reaplicar esquema / seed
docker compose logs -f app                      # ver arranque
```

`bin/check-env.php` es el primer sitio donde mirar si algo falla: comprueba las
10 extensiones que necesita la app y dice qué variable falta, sin imprimir
ningún valor.

### Notas sobre S3

No hay soporte de MinIO ni endpoint configurable: `S3Service` firma contra AWS
real. Las pruebas locales usan el bucket que digas en `S3_BUCKET_NAME`, así que
apunta a un bucket de pruebas, no al de producción.

---

## Despliegue en Railway (con Docker)

Railway construye el **Dockerfile de la raíz** directamente (PHP 8.3 + Apache,
las mismas extensiones y el mismo vhost que en local). Nada de Railpack, Caddy ni
Composer: la imagen es una sola, para local y para el despliegue, y el arranque es
el mismo `docker/entrypoint.sh`.

**Qué hace el entrypoint en el despliegue:** saca el host/puerto del PostgreSQL
de `DATABASE_URL` (cuando no hay `DB_HOST` explícito), espera a que responda,
deja las llaves de cifrado, aplica el esquema y siembra el admin
(`Setup::ensureDatabase()`), reconfigura Apache para escuchar en `$PORT` (la
variable de Railway) y arranca Apache.

**Variables de entorno en Railway:**

| Variable | Descripción |
|---|---|
| `DATABASE_URL` | Del plugin PostgreSQL (el entrypoint espera ese host) |
| `APP_DATA_KEY` | 64 hex. **Ponla fija**: reutiliza la del `.env` local para descifrar lo ya cifrado |
| `JWT_SECRET` | 64 hex |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_REGION`, `S3_BUCKET_NAME` | S3 de los adjuntos |
| `SEED_ADMIN_USERNAME`, `SEED_ADMIN_PASSWORD`, `SEED_ADMIN_ROLE` | Usuario del panel (se crea solo al crear el esquema; si no, `admin/setup.php`) |
| `MPV_*`, `PUBLIC_URL`, `AMSP_API_BASE`, `SESSION_DURATION_HOURS`, `MPV_PLAZO_OBSERVACION_DIAS` | Igual que en el `.env` |

**Pasos:**
1. Nuevo proyecto → *Deploy from GitHub repo* → *Add Postgres*.
2. Copiar el `DATABASE_URL` del plugin a la variable `DATABASE_URL`.
3. Añadir el resto de variables (tabla anterior). Sin `APP_DATA_KEY`/`JWT_SECRET`
   el entrypoint los genera en el primer arranque y se quedan en el volumen.
4. Dejar `Start Command` vacío y `Deploy` en automático. Railway conecta `$PORT`
   y el entrypoint mueve Apache a ese puerto.

**Qué no se sirve por HTTP** — el vhost de Apache en la imagen
(`docker/apache-vhost.conf`) devuelve 403 en `/includes/`, `/docs/`, `/tests/`,
`/bin/`, `/docker/`, `/vendor/`, `.git/`, `.env*` y los `.sql/.md/.log...`, igual
que local. La app no separa público de privado, esta configuración es la barrera.

**Notas**
- Sin `SEED_ADMIN_*`, el panel arranca igual: los `users` se crean desde
  `admin/setup.php` (bórralo o protégelo tras usarlo, como en producción).
- No usar `docker compose down -v` local: borra las llaves de cifrado.

---

## Puesta en marcha (desarrollo)

Sin Docker, con el servidor embebido de PHP (docroot debe ser el padre, porque
la app usa rutas absolutas `/solicitudes/...`):

```bash
cd migracion-app
php -S 127.0.0.1:8080
```

Abrir: http://127.0.0.1:8080/solicitudes/

Con Apache/nginx, apuntar el docroot a `migracion-app/` y acceder a
`/solicitudes/`.

---

## Páginas / rutas

| Ruta | Descripción |
|---|---|
| `/solicitudes/` | Simulador de préstamo (calculadora) |
| `/solicitudes/afiliacion.php` | Redirige a la MPV (`mpv/index.php?tipo=afiliacion`) |
| `/solicitudes/calculadora.php` | Calculadora de préstamos |
| `/solicitudes/guardar.php` | POST: persevera la solicitud en PostgreSQL |
| `/solicitudes/upload-url.php` | POST: genera URL S3 firmada para el adjunto |
| `/solicitudes/mpv/` | **Mesa de Partes Virtual** (formulario + transparencia) |
| `/solicitudes/acuse.php` | Acuse de recibo por trámite (`?id=&t=`) o por subsanación (`?sub=&t=`, también `?pdf=1`) |
| `/solicitudes/seguimiento.php` | Seguimiento público por cargo/expediente + DNI |
| `/solicitudes/subsanacion.php` | Subsanación pública de observaciones (Nº de cargo/expediente + DNI) |
| `/solicitudes/admin/` | **Panel administrativo** (migración del dashboard React a PHP puro) |

### Mesa de Partes Virtual (`/solicitudes/mpv/`)

- `mpv/index.php` — Formulario unificado de trámites (documento general,
  afiliación, crédito, pre-evaluación y prestaciones: auxilio por retiro,
  invalidez y fallecimiento, seguro de sepelio familiar y préstamo solidario).
  Cada trámite muestra sus **requisitos** documentales y el área de destino y
  asunto aparecen solo para documento general. Los documentos se adjuntan con
  subida multiparte a S3. Soporta preselección vía
  `?tipo={mpv|afiliacion|credito|pre-evaluacion|auxilio-retiro|auxilio-invalidez|seguro-sepelio|prestamo-solidario|auxilio-fallecimiento}`.
- La documentación institucional (directiva, política de privacidad, manual y
  soporte) ya no se sirve como páginas: vive como Markdown en `docs/`.

Cada presentación guarda en `submissions` los correlativos `nro_cargo`/`nro_expediente`
y un `acuse_hash` (HMAC-SHA256 con `JWT_SECRET`) que firma el acuse
(`acuse.php?id=<id>&t=<hash>`). El acuse también se descarga en **PDF**.

### Idempotencia y concurrencia

El `submissionId` que genera el cliente es la clave de idempotencia del alta:

- **Reintento.** Si el mismo `submissionId` vuelve a llegar (doble clic, timeout),
  el alta es un no-op: devuelve el **mismo** Nº de cargo, Nº de expediente y
  `acuse_hash` ya emitidos, con `"reenvio": true`. No duplica el expediente, ni
  los adjuntos, ni el movimiento de apertura, y **no quema correlativos**.
- **Carrera.** `guardar()` inserta con `ON CONFLICT (submission_id) DO NOTHING
  RETURNING`. Si dos peticiones con el mismo id llegan juntas, solo una inserta;
  la otra lee cero filas y responde con el acuse de la ganadora en vez de
  reventar con violación de unicidad.
- **Una sola transacción.** Correlativos, alta y trazabilidad entran en un único
  commit. Si algo falla, el rollback devuelve el contador de `correlativos` a su
  valor anterior, así que no se pierde el Nº de cargo sin destino y no quedan
  expedientes sin su movimiento de apertura.
- **Numeración garantizada por la base.** `uq_submissions_nro_cargo` y
  `uq_submissions_nro_expediente` son índices **únicos parciales**: si dos altas
  concurrentes lograran el mismo correlativo, la segunda revienta en vez de
  registrar un número repetido.
- **Transacciones anidables.** `DbConnection::transaccion()` lleva un contador de
  profundidad: PostgreSQL no anida, así que un `BEGIN` interno se ignora y el
  commit real ocurre al volver a profundidad 0.

> `DbConnection::inTransaction()` y los `DO $$` de `schema.sql` no tienen
> cobertura automatizada: requieren PostgreSQL real, que no está disponible en el
> entorno de pruebas. La suite (`php tests/run.php`) cubre la lógica de
> idempotencia con dobles.

El seguimiento público (`seguimiento.php`) devuelve la línea de tiempo de
`movimientos` (registro → derivaciones entre áreas → cambios de estado → observaciones
y subsanaciones) a partir del cargo/expediente + DNI.

### Observación y subsanación

El flujo completo vive en `includes/SubsanacionService.php`, con los datos en
`observaciones` y `subsanaciones` (`includes/ObservacionRepository.php` es el
único que las escribe).

- **Observar.** `registrarObservacion()` valida el detalle (máx. 1000
  caracteres) y el plazo (5 a 90 días, por defecto `MPV_PLAZO_OBSERVACION_DIAS`),
  abre el requerimiento y pasa el expediente a `observado`. Un índice único
  parcial sobre `observaciones` garantiza **una sola observación pendiente por
  expediente**; la observación, el cambio de estado y el movimiento van en un
  único commit.
- **Subsanar.** `subsanacion.php` pide el Nº de cargo o expediente y el DNI.
  El expediente **nunca** se localiza por el UUID que manda el cliente: bastaría
  conocerlo para subsanar un trámite ajeno. Cada subsanación emite su propio
  correlativo `S-AAAA-NNNNNN` y su acuse (`acuse.php?sub=<id>&t=<hash>`), y
  guarda los adjuntos en `files` con `subsanacion_id`. Al presentarse, la
  observación queda `atendida` y el expediente **sigue `observado`**: subsanar
  no resuelve nada por sí solo.
- **Revisar.** `revisarSubsanacion()` acepta (el expediente vuelve a
  `en_revision`) o rechaza (la observación vuelve a `pendiente` con plazo
  nuevo). El área también puede desistir: `admin/api/status.php` llama a
  `desestimar()` cuando el operador saca el expediente de `observado` por el
  selector de estado.
- **Idempotencia y plazo vencido.** Un reintento con el mismo `subsanacionId`
  devuelve el mismo acuse sin duplicar nada ni quemar correlativo. Vencido el
  plazo la subsanación **se admite igual**: el sistema avisa y decide el área.
- **Privacidad.** La descripción de la subsanación se cifra en reposo con
  `DataProtector`, igual que la del alta original.

### Previsualización de archivos

- **Antes de enviar** — `mpv/index.php` muestra miniaturas de las imágenes y
  permite abrir el **PDF o la imagen en un visor modal** (object URL) sin subirlos
  aún; los PDFs se renderizan en línea.
- **Solicitudes enviadas (asociado)** — `mis-solicitudes.php` expone los archivos
  de cada presentación (URLs presignadas S3); la sección "Mis solicitudes" del
  portal (`login.php`) muestra "Archivos (n)" y un visor modal con
  Previsualizar/Descargar.
- **Panel administrativo** — el modal de archivos (`admin/index.php`) incorpora
  "Previsualizar" (imagen o PDF en línea) además de la miniatura y la descarga.

Para que el visor en línea funcione, los CSP de `mpv/`, `login.php` y
`admin/index.php` permiten `blob:` / `frame-src https://*.s3.sa-east-1.amazonaws.com`.

### Panel administrativo (`/solicitudes/admin/`)

Dashboard en PHP puro (sin Node) equivalente al dashboard React original. Funciona
en cPanel con PHP ≥ 8.1 y guarda en la **misma base PostgreSQL** del portal y usa
el mismo bucket S3.

- `admin/login.php` — Login con JWT httpOnly (`dashboard_token`), bloqueo tras 5
  intentos fallidos durante 15 min y verificación de contraseñas compatible con
  los hashes `salt:hash` (scrypt de Node) **y** con `password_hash` (nuevos).
- `admin/index.php` — Listado de solicitudes con pestañas (Crédito / Afiliación /
  Pre-evaluación / Mesa de Partes), búsqueda por nombre o DNI, paginación, tarjetas
  de resumen, vista de archivos (URLs S3 firmadas), **seguimiento y derivación**,
  y acciones de estado/eliminación.
- `admin/api/*.php` — Endpoints JSON del panel (me, files, status, delete, areas,
  seguimiento, derivar), con `bootstrap.php` como carga común. `derivar.php` exige
  rol `admin`/`super-admin` y registra el movimiento en `movimientos` y la
  auditoría.
- `admin/auditoria.php` — Bitácora de auditoría de operaciones del panel.
- `admin/setup.php` — Crea el esquema completo (`schema.sql`) y el admin inicial.

**Puesta en marcha (una sola vez):**
1. Añadir al `.env` (ya incluido): `SESSION_DURATION_HOURS`, `SEED_ADMIN_USERNAME`,
   `SEED_ADMIN_PASSWORD` y `SEED_ADMIN_ROLE`.
2. Abrir `admin/setup.php` y pulsar *Crear/actualizar administrador*.
3. Entrar en `admin/login.php` con ese usuario y contraseña.

> Borra o protege `admin/setup.php` después de usarlo en producción.

### Flujo de `login.php`

1. El usuario envía su **DNI** y **fecha de nacimiento** (POST).
2. `AmspApiClient::getSocio()` consulta la API raíz con `dni`, `dia`, `mes`, `anio`.
3. Si el socio existe, `getSocio` devuelve la ficha y `getCuenta()` trae los
   movimientos de su préstamo.
4. El resultado se muestra en pantalla y queda en sesión (clave
   `amsp_cliente_dni`, vida 3600 s) para no tener que reingresar.

---

## API AMSP — Endpoints utilizados

### 1. Datos del asociado

```
GET https://amspweb.net/api/?dni={dni}&dia={dia}&mes={mes}&anio={anio}
```

Retorna un array de elementos (normalmente uno). Índices usados por la app:

| Índice | Campo |
|--------|-------|
| `[0]` | Código de asociado |
| `[1]` | Condición |
| `[2]` | Apellidos y nombres |
| `[3]` | N° de DNI |
| `[10]` | Fecha de ingreso |
| `[18]` | Compañía |
| `[19]` | Base |
| `[20]` | Establecimiento |
| `[21]` | U. Proceso |
| `[22]` | Ejecutora |

### 2. Estado de cuenta (movimientos)

**GET** `https://amspweb.net/api/cuenta/?id=<codigo_socio>`

Retorna un array de filas; cada fila es un array de **16 campos**:

Resumen:

| Índice | Campo | Nota |
|-------|--------|------|
| `[0]` | codigo_socio | |
| `[1]` | fecha_operacion | |
| `[2]` | fecha_inicio_periodo | |
| `[3]` | periodo (MM/AAAA) | |
| `[4]` | tipo_operacion | `01/03/0Z/2A` desembolso, `10` planilla |
| `[5]` | forma_pago | `1` = planilla |
| `[6]` | numero_prestamo | |
| `[7]` | numero_operacion | `12` = préstamo, `EQ` = planilla |
| `[8]` | numero_repetido | = índice 6 |
| `[9]` | correlativo | |
| `[10]` | monto_base | centésimas (100000 = S/ 1000.00) |
| `[11]` | fecha_vencimiento | `1899-12-30` = sin dato |
| `[12]` | descripcion | `Prest AMSP`, `P/Planilla`, ... |
| `[13]` | cuota | 1, 2, 3, ... |
| `[14]` | monto (cargo/abono) | negativo = abono |
| `[15]` | saldo | saldo acumulado |

> La app muestra `—` cuando la fecha es `1899-12-30` (fecha cero del sistema fuente).

---

## Estructura

```
/solicitudes
├── .env                    # Variables reales (NO commiterar)
├── Dockerfile             # Imagen PHP 8.3 + Apache (local y Railway)
├── index.php              # Simulador de préstamo
├── login.php              # Portal del asociado (DNI + fecha nacimiento)
├── afiliacion.php         # Redirige a la MPV (trámite de afiliación)
├── guardar.php            # Guarda solicitudes
├── upload-url.php         # Genera URL S3 firmada (kms/AES256)
├── acuse.php              # Acuse de recibo (HTML/PDF), de alta y de subsanación
├── seguimiento.php        # Seguimiento público de expedientes
├── subsanacion.php        # Subsanación pública de observaciones
├── guardar-subsanacion.php # POST: registra la subsanación (idempotente)
├── mis-solicitudes.php    # Listado de presentaciones del asociado
├── README.md              # Este documento
├── schema.sql             # Esquema único de la BD (solicitudes, MPV, panel, auditoría)
├── mpv/                   # Mesa de Partes Virtual
│   └── index.php          #   Formulario unificado (9 trámites, ?tipo=, requisitos)
├── docker/                # Soporte de la imagen (entrypoint y vhost)
│   ├── entrypoint.sh      #   Espera la BD (DATABASE_URL o DB_HOST), aplica esquema,
│   │                      #   deja APP_DATA_KEY/JWT_SECRET y mueve Apache a $PORT
│   ├── apache-vhost.conf  #   Vhost: bloquea includes/, .env, .sql/.md...
│   └── php-dev.ini        #   PHP para desarrollo local
├── bin/                   # CLI solo local (check-env, migrate)
├── docs/                  # Documentación institucional en Markdown (no se sirve por HTTP)
│   ├── directiva.md
│   ├── politica-de-privacidad.md
│   ├── manual-de-procedimientos.md
│   └── soporte-y-contingencia.md
├── tests/                 # Suite propia: php tests/run.php
├── admin/                 # Panel administrativo
│   ├── login.php, index.php, auditoria.php, setup.php
│   └── api/               # bootstrap, me, files, status, delete, areas, seguimiento,
│                          #   derivar, observacion, subsanacion
└── includes/
    ├── AmspApiClient.php       # Cliente HTTP de la API AMSP
    ├── config.php              # Carga .env y devuelve configuración
    ├── Database.php            # Conexión PostgreSQL (doble driver)
    ├── S3Service.php           # Firmado y subida a S3 (SSE-AES-256)
    ├── Setup.php               # Setup automático (schema base + MPV)
    ├── SubmissionRepository.php # Persistencia de solicitudes y adjuntos
    ├── ObservacionRepository.php # Persistencia de observaciones y subsanaciones
    ├── SubsanacionService.php   # Caso de uso: observar, subsanar, revisar
    ├── CorrelativoRepository.php # Correlativos C- / E- / S-
    ├── Auth.php                # Utilidades (escape, formato, ...)
    ├── DataProtector.php       # Cifrado AES-256-GCM de datos personales
    ├── PdfBuilder.php          # Generador mínimo de PDF (acuse)
    ├── AcuseService.php        # Hash, acuse HTML/PDF, áreas, movimientos
    ├── Audit.php               # Bitácora de auditoría
    ├── Dashboard.php           # Sesión JWT, roles, listado del panel
    ├── helpers.php             # Utilidades (escape, formato, ...)
    ├── pre-evaluacion.php      # Formulario de pre-evaluación
    └── solicitud-credito.php   # Formulario de solicitud de crédito
```

---

## Seguridad

- Las credenciales y la clave de cifrado viven en `.env` (fuera del control de versiones).
- Validación de DNI (8 dígitos) y fecha de nacimiento con `checkdate`.
- Escape de salida con `htmlspecialchars` (`e()`) para evitar XSS; CSP con hash en
  páginas que inyectan HTML.
- Tokens CSRF por sesión en los formularios que persisten datos.
- Generación de URLs S3 firmadas (vigencia breve) con expiración de sesión.
- **Cifrado en reposo**: documentos en S3 con SSE-AES-256 (header firmado en la
  presigned URL y enviado en el PUT) y descripciones de trámites y de
  subsanaciones en PostgreSQL con AES-256-GCM (`DataProtector`).
- **Roles**: `admin`, `empleado` y `super-admin`; derivación restringida a
  `admin`/`super-admin`.
- **Auditoría**: `audit_log` registra login, cambios de estado, derivaciones,
  observaciones, revisiones de subsanación, vistas de archivos y eliminaciones.
- **Acuse**: huella HMAC-SHA256 (`JWT_SECRET`) sobre id + números; para verlo por
  URL se exige el hash o sesión administrativa.