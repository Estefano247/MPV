-- =====================================================================
-- ESQUEMA ÚNICO DE /solicitudes (PostgreSQL)
-- =====================================================================
-- Reúne, en orden de dependencias, el esquema base (submissions/files),
-- el esquema de la Mesa de Partes Virtual (areas, correlativos,
-- movimientos), la tabla de usuarios del panel (users) y la bitácora de
-- auditoría (audit_log).
--
-- Idempotente: puede ejecutarse varias veces (IF NOT EXISTS, ON CONFLICT,
-- ADD COLUMN IF NOT EXISTS). Se aplica automáticamente desde Setup.php.
-- =====================================================================

-- 1. Solicitudes y adjuntos (esquema base)
CREATE TABLE IF NOT EXISTS submissions (
    submission_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    type VARCHAR(50) NOT NULL DEFAULT 'credito' CHECK (type IN ('credito', 'afiliacion', 'pre-evaluacion', 'mpv',
                        'auxilio-retiro', 'auxilio-invalidez', 'seguro-sepelio',
                        'prestamo-solidario', 'auxilio-fallecimiento')),
    name VARCHAR(200) NOT NULL,
    email VARCHAR(255) NOT NULL,
    dni VARCHAR(8) NOT NULL,
    telefono VARCHAR(20) NOT NULL DEFAULT '',
    fecha_solicitud TIMESTAMP NOT NULL DEFAULT NOW(),
    description VARCHAR(2000) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    status VARCHAR(50) NOT NULL DEFAULT 'pendiente' CHECK (status IN ('pendiente', 'en_revision', 'observado', 'aprobado', 'denegado'))
);

CREATE TABLE IF NOT EXISTS files (
    file_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    s3_key VARCHAR(1024) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    submission_id UUID NOT NULL REFERENCES submissions(submission_id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_submissions_status ON submissions(status);
CREATE INDEX IF NOT EXISTS idx_files_submission_id ON files(submission_id);

-- 2. Usuarios administradores del panel (admin/)
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'admin' CHECK (role IN ('admin', 'empleado', 'super-admin')),
    active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    last_login_at TIMESTAMP,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);

-- 3. Mesa de Partes Virtual: áreas de derivación
CREATE TABLE IF NOT EXISTS areas (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL UNIQUE,
    siglas VARCHAR(20) NOT NULL DEFAULT '',
    activa BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- 4. Correlativos anuales de Nº de expediente y Nº de cargo
CREATE TABLE IF NOT EXISTS correlativos (
    id SERIAL PRIMARY KEY,
    anno INTEGER NOT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'expediente', -- expediente | cargo
    seq INTEGER NOT NULL DEFAULT 1,
    UNIQUE (anno, tipo)
);

-- 5. Movimientos / trazabilidad (registro, derivación, estado, observación,
--    subsanación y resolución)
CREATE TABLE IF NOT EXISTS movimientos (
    id BIGSERIAL PRIMARY KEY,
    submission_id UUID NOT NULL REFERENCES submissions(submission_id) ON DELETE CASCADE,
    tipo VARCHAR(30) NOT NULL DEFAULT 'registro', -- registro | derivacion | estado | observacion | subsanacion | resolucion
    de_area_id INTEGER REFERENCES areas(id) ON DELETE SET NULL,
    a_area_id INTEGER REFERENCES areas(id) ON DELETE SET NULL,
    estado VARCHAR(50),
    descripcion VARCHAR(500) NOT NULL DEFAULT '',
    usuario VARCHAR(50),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_movimientos_submission_id ON movimientos(submission_id);
CREATE INDEX IF NOT EXISTS idx_movimientos_created_at ON movimientos(created_at DESC);

-- 6. Extensión de submissions (Nº cargo/expediente, área actual, acuse, cifrado)
ALTER TABLE submissions ALTER COLUMN description TYPE TEXT;
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS nro_cargo VARCHAR(40);
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS nro_expediente VARCHAR(40);
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS area_actual_id INTEGER REFERENCES areas(id) ON DELETE SET NULL;
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS acuse_hash VARCHAR(64);
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS acuse_at TIMESTAMP,
ADD COLUMN IF NOT EXISTS cifrado BOOLEAN NOT NULL DEFAULT false;

CREATE INDEX IF NOT EXISTS idx_submissions_nro_cargo ON submissions(nro_cargo);
CREATE INDEX IF NOT EXISTS idx_submissions_nro_expediente ON submissions(nro_expediente);
CREATE INDEX IF NOT EXISTS idx_submissions_acuse_hash ON submissions(acuse_hash);

-- Unicidad de la numeración oficial, garantizada por la base y no solo por la
-- aplicación: si dos altas concurrentes lograran el mismo correlativo, la segunda
-- revienta con violación de unicidad en vez de registrar un número repetido.
--
-- Cada índice se crea dentro de un bloque DO que verifica antes que no haya
-- duplicados. Si los hubiera, avisa por NOTICE y lo omite en lugar de fallar:
-- Setup::ensureDatabase() se ejecuta en cada alta, así que un error aquí dejaría
-- el portal entero sin poder registrar solicitudes. Los índices son parciales
-- porque las filas sin numerar (nro_* NULL) no compiten entre sí.
-- Cada bloque se omite si el índice ya existe, así que el costo en cada request es
-- un lookup en el catálogo, no un GROUP BY sobre la tabla.
DO $$
DECLARE
    duplicados INTEGER;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'uq_submissions_nro_cargo') THEN
        SELECT COUNT(*) INTO duplicados FROM (
            SELECT nro_cargo FROM submissions WHERE nro_cargo IS NOT NULL
            GROUP BY nro_cargo HAVING COUNT(*) > 1
        ) d;
        IF duplicados > 0 THEN
            RAISE NOTICE 'Omitido uq_submissions_nro_cargo: % nro_cargo repetidos. Depura los datos y vuelve a ejecutar el esquema.', duplicados;
        ELSE
            CREATE UNIQUE INDEX uq_submissions_nro_cargo
                ON submissions(nro_cargo) WHERE nro_cargo IS NOT NULL;
        END IF;
    END IF;
END $$;

DO $$
DECLARE
    duplicados INTEGER;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'uq_submissions_nro_expediente') THEN
        SELECT COUNT(*) INTO duplicados FROM (
            SELECT nro_expediente FROM submissions WHERE nro_expediente IS NOT NULL
            GROUP BY nro_expediente HAVING COUNT(*) > 1
        ) d;
        IF duplicados > 0 THEN
            RAISE NOTICE 'Omitido uq_submissions_nro_expediente: % nro_expediente repetidos. Depura los datos y vuelve a ejecutar el esquema.', duplicados;
        ELSE
            CREATE UNIQUE INDEX uq_submissions_nro_expediente
                ON submissions(nro_expediente) WHERE nro_expediente IS NOT NULL;
        END IF;
    END IF;
END $$;

-- Refuerza la idempotencia del alta: un mismo adjunto no puede registrarse dos
-- veces dentro de la misma presentación.
DO $$
DECLARE
    duplicados INTEGER;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'uq_files_submission_s3_key') THEN
        SELECT COUNT(*) INTO duplicados FROM (
            SELECT submission_id, s3_key FROM files
            GROUP BY submission_id, s3_key HAVING COUNT(*) > 1
        ) d;
        IF duplicados > 0 THEN
            RAISE NOTICE 'Omitido uq_files_submission_s3_key: % adjuntos repetidos por presentación.', duplicados;
        ELSE
            CREATE UNIQUE INDEX uq_files_submission_s3_key
                ON files(submission_id, s3_key);
        END IF;
    END IF;
END $$;

-- 7. Tipos de trámite: "mpv" (documento general), "credito", "afiliacion",
--    "pre-evaluacion" y prestaciones/beneficios (auxilio por retiro, invalidez
--    y fallecimiento, seguro de sepelio familiar y préstamo solidario).
ALTER TABLE submissions DROP CONSTRAINT IF EXISTS submissions_type_check;
ALTER TABLE submissions ADD CONSTRAINT submissions_type_check
    CHECK (type IN ('credito', 'afiliacion', 'pre-evaluacion', 'mpv',
                    'auxilio-retiro', 'auxilio-invalidez', 'seguro-sepelio',
                    'prestamo-solidario', 'auxilio-fallecimiento'));

-- 7b. Estados del expediente. "observado" se suma al CHECK original (que se
--     llamaba submissions_status_check) porque el alta ahora puede dejar el
--     expediente esperando una subsanación. El CHECK solo puede cambiarse
--     quitándolo y volviéndolo a poner: por eso se repite aquí en vez de
--     depender del CREATE TABLE inicial.
ALTER TABLE submissions DROP CONSTRAINT IF EXISTS submissions_status_check;
ALTER TABLE submissions ADD CONSTRAINT submissions_status_check
    CHECK (status IN ('pendiente', 'en_revision', 'observado', 'aprobado', 'denegado'));

-- 8. users: área asignada (control de acceso / derivación)
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check
    CHECK (role IN ('admin', 'empleado', 'super-admin'));
ALTER TABLE users ADD COLUMN IF NOT EXISTS area_id INTEGER REFERENCES areas(id) ON DELETE SET NULL;

-- 9. Áreas iniciales de la entidad (idempotente)
INSERT INTO areas (nombre, siglas) VALUES
    ('Mesa de Partes Virtual', 'MPV'),
    ('Dirección General', 'DG'),
    ('Evaluación Crediticia', 'EC'),
    ('Afiliaciones', 'AF'),
    ('Asesoría Legal', 'AL'),
    ('Tesorería', 'TES'),
    ('Contabilidad', 'CON'),
    ('Archivo Central', 'ARC')
ON CONFLICT (nombre) DO NOTHING;

-- 10. Bitácora de auditoría del panel administrativo (admin/)
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    username VARCHAR(50),
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50),
    entity_id VARCHAR(64),
    details JSONB,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_log_action ON audit_log(action);
CREATE INDEX IF NOT EXISTS idx_audit_log_entity ON audit_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_log_username ON audit_log(username);

-- 11. Observaciones del expediente (requerimiento de subsanación)
--
-- Es el requerimiento que la Mesa de Partes (o el área responsable) le hace al
-- presentante cuando la documentación presentada no cumple los requisitos del
-- trámite: en vez de resolver de una, deja constancia del what's missing y
-- abre un plazo para que el asociado lo subsane (Ley 27444, art. 115.5).
--
-- `plazo_dias` y `fecha_limite` se guardan los dos: los días son la regla
-- aplicada (y se muestran en la UI) y la fecha es el vencimiento real, que no
-- depende de cuándo se lea la fila. `estado` va de 'pendiente' a 'atendida'
-- cuando el presentante entrega la subsanación; un plazo vencido no es un
-- estado (es una fecha que ya pasó) y se calcula al mostrar.
CREATE TABLE IF NOT EXISTS observaciones (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    submission_id UUID NOT NULL REFERENCES submissions(submission_id) ON DELETE CASCADE,
    detalle VARCHAR(1000) NOT NULL,
    plazo_dias INTEGER NOT NULL DEFAULT 10,
    fecha_limite TIMESTAMP NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'atendida')),
    usuario VARCHAR(50) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    atendida_at TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_observaciones_submission_id ON observaciones(submission_id);
CREATE INDEX IF NOT EXISTS idx_observaciones_estado ON observaciones(estado);

-- Un expediente no puede tener dos observaciones abiertas a la vez: si ya hay
-- una pendiente, la nueva se rechaza en la aplicación. Este índice único parcial
-- lo blinda también desde la base, para que dos operadores simultáneos no
-- consigan dejar dos requerimientos vivos sobre el mismo expediente.
DO $$
DECLARE
    duplicados INTEGER;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'uq_observaciones_abierta') THEN
        SELECT COUNT(*) INTO duplicados FROM (
            SELECT submission_id FROM observaciones WHERE estado = 'pendiente'
            GROUP BY submission_id HAVING COUNT(*) > 1
        ) d;
        IF duplicados > 0 THEN
            RAISE NOTICE 'Omitido uq_observaciones_abierta: % expedientes con mas de una observacion pendiente. Depura los datos y vuelve a ejecutar el esquema.', duplicados;
        ELSE
            CREATE UNIQUE INDEX uq_observaciones_abierta
                ON observaciones(submission_id) WHERE estado = 'pendiente';
        END IF;
    END IF;
END $$;

-- 12. Subsanaciones (presentación del asociado para levantar una observación)
--
-- Una subsanación es una *nueva presentación* sobre el mismo expediente, no una
-- edición de la anterior: por eso tiene su propio UUID, su propio Nº de cargo y
-- su propio acuse (acuse.php?sub=...&t=...). El vínculo con la observación
-- atendida queda explícito, y el estado propio permite que el área acepte o
-- rechace lo presentado sin tocar la observación original.
CREATE TABLE IF NOT EXISTS subsanaciones (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    submission_id UUID NOT NULL REFERENCES submissions(submission_id) ON DELETE CASCADE,
    observacion_id UUID REFERENCES observaciones(id) ON DELETE SET NULL,
    dni VARCHAR(8) NOT NULL,
    nombre VARCHAR(200) NOT NULL,
    email VARCHAR(255) NOT NULL DEFAULT '',
    telefono VARCHAR(20) NOT NULL DEFAULT '',
    descripcion VARCHAR(2000) NOT NULL DEFAULT '',
    nro_cargo VARCHAR(40) NOT NULL,
    acuse_hash VARCHAR(64) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'registrada' CHECK (estado IN ('registrada', 'aceptada', 'rechazada')),
    usuario VARCHAR(50) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    revisada_at TIMESTAMP,
    revisada_por VARCHAR(50)
);

CREATE INDEX IF NOT EXISTS idx_subsanaciones_submission_id ON subsanaciones(submission_id);
CREATE INDEX IF NOT EXISTS idx_subsanaciones_observacion_id ON subsanaciones(observacion_id);

-- El acuse de la subsanación se firma igual que el del alta (HMAC sobre id y
-- Nº de cargo), pero el número es propio de la subsanación, así que necesita
-- su propio índice único.
DO $$
DECLARE
    duplicados INTEGER;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'uq_subsanaciones_nro_cargo') THEN
        SELECT COUNT(*) INTO duplicados FROM (
            SELECT nro_cargo FROM subsanaciones GROUP BY nro_cargo HAVING COUNT(*) > 1
        ) d;
        IF duplicados > 0 THEN
            RAISE NOTICE 'Omitido uq_subsanaciones_nro_cargo: % numeros de cargo de subsanacion repetidos. Depura los datos y vuelve a ejecutar el esquema.', duplicados;
        ELSE
            CREATE UNIQUE INDEX uq_subsanaciones_nro_cargo ON subsanaciones(nro_cargo);
        END IF;
    END IF;
END $$;

-- Los adjuntos de la subsanación cuelgan del mismo expediente (para que
-- `eliminar()` y el borrado en S3 sigan funcionando igual) y además guardan a
-- qué subsanación pertenecen, que es lo que permite mostrar "estos 3 archivos
-- son los de la subsanación" en el panel sin inferirlo por fecha.
ALTER TABLE files ADD COLUMN IF NOT EXISTS subsanacion_id UUID REFERENCES subsanaciones(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_files_subsanacion_id ON files(subsanacion_id);
