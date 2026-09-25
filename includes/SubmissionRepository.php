<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DataProtector.php';
require_once __DIR__ . '/SubmissionRepositoryInterface.php';

/**
 * Implementación PostgreSQL de SubmissionRepositoryInterface. Único dueño del
 * acceso a las tablas `submissions`, `files` y `movimientos`.
 *
 * Las páginas y los endpoints del panel no escriben SQL: piden datos por
 * criterio de negocio (porId, listar, archivos, ...). Cualquier consulta
 * nueva que necesite tocar esas tablas debe entrar por acá.
 */
final class SubmissionRepository implements SubmissionRepositoryInterface
{
    public const TYPES = [
        'afiliacion', 'pre-evaluacion', 'credito', 'mpv',
        'auxilio-retiro', 'auxilio-invalidez', 'seguro-sepelio',
        'prestamo-solidario', 'auxilio-fallecimiento',
    ];

    /**
     * Sinónimos que se aceptan al guardar pero nunca se almacenan.
     *
     * 'credito' y 'prestamo-solidario' son el mismo trámite: idénticos
     * requisitos escritos de dos formas. Se acepta el antiguo para no romper
     * clientes ni enlaces en caché, pero se persiste el canónico, así el
     * expediente no queda partido en dos según por dónde entró.
     */
    private const ALIAS = [
        'credito' => 'prestamo-solidario',
    ];

    /**
     * Normaliza el tipo recibido al valor con el que se guarda.
     *
     * @return string|null null si el tipo no existe
     */
    public static function tipoCanonico(string $tipo): ?string
    {
        $tipo = self::ALIAS[$tipo] ?? $tipo;

        return in_array($tipo, self::TYPES, true) ? $tipo : null;
    }

    /** Columnas de `submissions` que interesan al panel, con el área actual. */
    private const COLUMNAS = 's.submission_id, s.type, s.name, s.email, s.dni, s.telefono, s.description,
            s.status, s.fecha_solicitud, s.created_at, s.nro_cargo, s.nro_expediente,
            s.acuse_hash, s.acuse_at, s.area_actual_id, a.nombre AS area_actual';

    private const JOIN_AREA = 'LEFT JOIN areas a ON a.id = s.area_actual_id';

    private DbConnection $db;

    public function __construct(?DbConnection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Ejecuta $fn dentro de una transacción de la conexión del repository.
     *
     * Anidable: si ya hay una transacción en curso, la reutiliza en vez de abrir
     * otra, porque PostgreSQL no las anida. Eso permite que un caso de uso
     * envuelva en un solo commit lo que varios repositorios tocan.
     *
     * Solo cierra lo que sigue abierto. Si el callback ya hizo su propio
     * rollBack(), intentar un commit sin transacción activa lanzaría y taparía el
     * error que se estaba propagando.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaccion(callable $fn): mixed
    {
        $this->db->beginTransaction();
        try {
            $resultado = $fn($this);
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
            return $resultado;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // -----------------------------------------------------------------
    // Escritura
    // -----------------------------------------------------------------

    /**
     * @param array{nombre:string,email:string,dni:string,telefono:string,descripcion:string,tipo:string} $datos
     * @param array<int, array{file:string,nombre:string,mime:string}> $archivos
     * @param string|null $id UUID opcional (mismo que el de S3) para poder vincular
     *                       la carpeta del storage con el registro.
     * @param array{nro_cargo:string,nro_expediente:string,acuse_hash:string}|null $acuse
     * @return array{id:string,creado:bool,nro_cargo:?string,nro_expediente:?string,acuse_hash:?string}
     */
    public function guardar(array $datos, array $archivos, ?string $id = null, ?array $acuse = null): array
    {
        $submissionId = $id ?? Database::uuid();

        $descripcion = (string) $datos['descripcion'];
        $cifrado = false;
        if ($descripcion !== '') {
            $descripcion = (string) DataProtector::encrypt($descripcion);
            $cifrado = true;
        }

        return $this->transaccion(function () use ($datos, $archivos, $submissionId, $descripcion, $cifrado, $acuse): array {
            // ON CONFLICT DO NOTHING + RETURNING es la barrera de la carrera: si dos
            // peticiones con el mismo id llegan juntas, solo una inserta; la otra
            // recibe cero filas y cae en la rama de reintento de abajo. INSERT
            // pelado tiraría violación de unicidad y el cliente perdería un acuse
            // que en realidad ya se había emitido.
            $stmt = $this->db->prepare(
                'INSERT INTO submissions (submission_id, type, name, email, dni, telefono, fecha_solicitud, description, status,
                        nro_cargo, nro_expediente, acuse_hash, acuse_at, cifrado)
                 VALUES (:id, :type, :name, :email, :dni, :telefono, NOW(), :description, :status,
                        :nro_cargo, :nro_expediente, :acuse_hash, NOW(), :cifrado)
                 ON CONFLICT (submission_id) DO NOTHING
                 RETURNING submission_id'
            );
            $stmt->execute([
                ':id' => $submissionId,
                ':type' => $datos['tipo'],
                ':name' => mb_substr($datos['nombre'], 0, 200),
                ':email' => mb_substr($datos['email'], 0, 255),
                ':dni' => $datos['dni'],
                ':telefono' => mb_substr($datos['telefono'], 0, 20),
                ':description' => $descripcion,
                ':status' => 'pendiente',
                ':nro_cargo' => $acuse['nro_cargo'] ?? null,
                ':nro_expediente' => $acuse['nro_expediente'] ?? null,
                ':acuse_hash' => $acuse['acuse_hash'] ?? null,
                ':cifrado' => $cifrado,
            ]);

            if ($stmt->fetchAll() === []) {
                // Ya existía: se devuelven sus datos para que el reintento responda
                // con el mismo acuse, y no se tocan los adjuntos ya registrados.
                $existente = $this->porId($submissionId);
                return [
                    'id' => $submissionId,
                    'creado' => false,
                    'nro_cargo' => $existente === null ? null : $this->texto($existente['nro_cargo'] ?? null),
                    'nro_expediente' => $existente === null ? null : $this->texto($existente['nro_expediente'] ?? null),
                    'acuse_hash' => $existente === null ? null : $this->texto($existente['acuse_hash'] ?? null),
                ];
            }

            $fileStmt = $this->db->prepare(
                'INSERT INTO files (file_id, s3_key, original_name, file_type, submission_id)
                 VALUES (:file_id, :s3_key, :original_name, :file_type, :submission_id)
                 ON CONFLICT (submission_id, s3_key) DO NOTHING'
            );
            foreach ($archivos as $archivo) {
                $fileStmt->execute([
                    ':file_id' => Database::uuid(),
                    ':s3_key' => $archivo['file'],
                    ':original_name' => mb_substr($archivo['nombre'], 0, 255),
                    ':file_type' => $archivo['mime'],
                    ':submission_id' => $submissionId,
                ]);
            }

            return [
                'id' => $submissionId,
                'creado' => true,
                'nro_cargo' => $acuse['nro_cargo'] ?? null,
                'nro_expediente' => $acuse['nro_expediente'] ?? null,
                'acuse_hash' => $acuse['acuse_hash'] ?? null,
            ];
        });
    }

    /** Normaliza un valor de columna a string o null. */
    private function texto(mixed $valor): ?string
    {
        return $valor === null ? null : (string) $valor;
    }

    public function actualizarEstado(string $id, string $status): void
    {
        $this->db->prepare('UPDATE submissions SET status = :status WHERE submission_id = :id')
            ->execute([':status' => $status, ':id' => $id]);
    }

    public function actualizarArea(string $id, ?int $areaId): void
    {
        $this->db->prepare('UPDATE submissions SET area_actual_id = :area WHERE submission_id = :id')
            ->execute([':area' => $areaId, ':id' => $id]);
    }

    /** `files` cae por ON DELETE CASCADE. */
    public function eliminar(string $id): void
    {
        $this->db->prepare('DELETE FROM submissions WHERE submission_id = :id')
            ->execute([':id' => $id]);
    }

    // -----------------------------------------------------------------
    // Lectura
    // -----------------------------------------------------------------

    /**
     * @return array<string,mixed>|null La fila completa, o null si no existe.
     */
    public function porId(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . '
               FROM submissions s
               ' . self::JOIN_AREA . '
              WHERE s.submission_id = :id'
        );
        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll();
        return $rows === [] ? null : $rows[0];
    }

    /**
     * Listado paginado del panel con los filtros de la barra de búsqueda.
     *
     * @param array{tipo:string,buscar:string,estados:string[]} $filtros
     * @return array<int, array<string,mixed>>
     */
    public function listar(array $filtros, int $limit, int $offset): array
    {
        [$whereSql, $params] = $this->condiciones($filtros);
        $params[':limite'] = $limit;
        $params[':offset'] = $offset;

        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . '
               FROM submissions s
               ' . self::JOIN_AREA . '
              WHERE ' . $whereSql . '
              ORDER BY s.created_at DESC
              LIMIT :limite OFFSET :offset'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Conteo agrupado por estado, con los mismos filtros que listar().
     * De ahí salen las tarjetas de resumen y el total de páginas.
     *
     * @param array{tipo:string,buscar:string,estados:string[]} $filtros
     * @return array<string,int>
     */
    public function contarPorEstado(array $filtros): array
    {
        [$whereSql, $params] = $this->condiciones($filtros);

        $stmt = $this->db->prepare(
            "SELECT status, COUNT(*) AS total FROM submissions WHERE {$whereSql} GROUP BY status"
        );
        $stmt->execute($params);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }
        return $counts;
    }

    /**
     * Historial del asociado: todas sus solicitudes, de la más reciente a la más antigua.
     *
     * @return array<int, array<string,mixed>>
     */
    public function porDni(string $dni): array
    {
        $stmt = $this->db->prepare(
            'SELECT submission_id, type, description, status, fecha_solicitud,
                    nro_cargo, nro_expediente, acuse_hash
               FROM submissions
              WHERE dni = :dni
              ORDER BY fecha_solicitud DESC'
        );
        $stmt->execute([':dni' => $dni]);
        return $stmt->fetchAll();
    }

    /**
     * Localiza un expediente por el Nº de cargo o de expediente indicado en el acuse.
     *
     * @return array<string,mixed>|null
     */
    public function buscarPorAcuse(string $dni, string $numero): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . '
               FROM submissions s
               ' . self::JOIN_AREA . '
              WHERE s.dni = :dni AND (s.nro_cargo = :c OR s.nro_expediente = :c)'
        );
        $stmt->execute([':dni' => $dni, ':c' => $numero]);
        $rows = $stmt->fetchAll();
        return $rows === [] ? null : $rows[0];
    }

    /**
     * Adjuntos de un expediente, en el orden en que se subieron.
     *
     * @return array<int, array<string,mixed>>
     */
    public function archivos(string $submissionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT file_id, s3_key, original_name, file_type, created_at
               FROM files
              WHERE submission_id = :id
              ORDER BY created_at ASC'
        );
        $stmt->execute([':id' => $submissionId]);
        return $stmt->fetchAll();
    }

    /**
     * Claves de S3 de un expediente, para purgar el storage antes de borrarlo.
     *
     * @return string[]
     */
    public function s3Keys(string $submissionId): array
    {
        $stmt = $this->db->prepare('SELECT s3_key FROM files WHERE submission_id = :id');
        $stmt->execute([':id' => $submissionId]);
        return array_map(
            static fn (array $row): string => (string) $row['s3_key'],
            $stmt->fetchAll()
        );
    }

    public function contarArchivos(string $submissionId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS total FROM files WHERE submission_id = :id');
        $stmt->execute([':id' => $submissionId]);
        return (int) ($stmt->fetchAll()[0]['total'] ?? 0);
    }

    // -----------------------------------------------------------------
    // Trazabilidad (movimientos del expediente)
    // -----------------------------------------------------------------

    public function registrarMovimiento(
        string $submissionId,
        string $tipo,
        ?string $descripcion = null,
        ?int $deArea = null,
        ?int $aArea = null,
        ?string $estado = null,
        ?string $usuario = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO movimientos (submission_id, tipo, de_area_id, a_area_id, estado, descripcion, usuario, created_at)
             VALUES (:sid, :tipo, :de, :a, :estado, :desc, :usuario, NOW())'
        );
        $stmt->execute([
            ':sid' => $submissionId,
            ':tipo' => $tipo,
            ':de' => $deArea,
            ':a' => $aArea,
            ':estado' => $estado,
            ':desc' => mb_substr((string) ($descripcion ?? ''), 0, 500),
            ':usuario' => mb_substr((string) ($usuario ?? ''), 0, 50),
        ]);
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function movimientos(string $submissionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.id, m.tipo, m.estado, m.descripcion, m.usuario, m.created_at,
                    da.nombre AS de_area, aa.nombre AS a_area
               FROM movimientos m
               LEFT JOIN areas da ON da.id = m.de_area_id
               LEFT JOIN areas aa ON aa.id = m.a_area_id
              WHERE m.submission_id = :sid
              ORDER BY m.id ASC'
        );
        $stmt->execute([':sid' => $submissionId]);
        return $stmt->fetchAll();
    }

    /**
     * WHERE parametrizado de los filtros del panel. El tipo es obligatorio;
     * la búsqueda libre y los estados son opcionales.
     *
     * @param array{tipo:string,buscar:string,estados:string[]} $filtros
     * @return array{0:string,1:array<string,mixed>} [sql, parámetros]
     */
    private function condiciones(array $filtros): array
    {
        $where = ['type = :tipo'];
        $params = [':tipo' => $filtros['tipo']];

        if ($filtros['buscar'] !== '') {
            $params[':buscar'] = '%' . $filtros['buscar'] . '%';
            $where[] = '(name ILIKE :buscar OR dni ILIKE :buscar)';
        }

        if ($filtros['estados'] !== []) {
            $conds = [];
            foreach ($filtros['estados'] as $i => $estado) {
                $key = ':st' . $i;
                $params[$key] = $estado;
                $conds[] = "status = {$key}";
            }
            $where[] = '(' . implode(' OR ', $conds) . ')';
        }

        return [implode(' AND ', $where), $params];
    }
}
