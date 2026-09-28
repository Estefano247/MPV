<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DataProtector.php';

/**
 * Observaciones y subsanaciones del expediente.
 *
 * La interfaz existe para que los casos de uso no dependan de la conexión:
 * SubsanacionService la recibe inyectada y en pruebas se le pasa un doble.
 *
 * Solo esta clase toca `observaciones` y `subsanaciones`. Los adjuntos siguen
 * perteneciendo a SubmissionRepository (que es la única que escribe en
 * `files`), igual que los movimientos.
 */
interface ObservacionRepositoryInterface
{
    /**
     * Abre una observación sobre el expediente. Devuelve la fila creada.
     *
     * @return array<string,mixed>
     */
    public function registrar(string $submissionId, string $detalle, int $plazoDias, string $usuario): array;

    /**
     * Todas las observaciones del expediente, de la más reciente a la más
     * antigua.
     *
     * @return array<int, array<string,mixed>>
     */
    public function porSubmission(string $submissionId): array;

    /**
     * La observación pendiente del expediente, o null si ya fue atendida.
     *
     * @return array<string,mixed>|null
     */
    public function pendiente(string $submissionId): ?array;

    /**
     * @return array<string,mixed>|null
     */
    public function porId(string $observacionId): ?array;

    /**
     * Marca la observación como atendida (el presentante ya entregó la
     * subsanación). El área la revisa después.
     */
    public function atender(string $observacionId): void;

    /**
     * Vuelve a 'pendiente' una observación que se había atendido, con nuevo
     * plazo. Se usa cuando el área rechaza la subsanación: el requisito sigue
     * en pie y el asociado tiene otra oportunidad.
     */
    public function reabrir(string $observacionId, string $nuevaFechaLimite): void;

    /**
     * Cierra la observación sin subsanación porque el área se desistió del
     * requerimiento. Queda como 'atendida' con su fecha de cierre, para que la
     * historia del expediente siga siendo legible.
     */
    public function desestimar(string $observacionId): void;

    /**
     * Registra la subsanación con su Nº de cargo y su acuse.
     *
     * Es idempotente sobre `$id`: si ya existe, no inserta nada y devuelve
     * `creado: false` con los datos de la fila existente, igual que
     * SubmissionRepository::guardar() con el alta del expediente.
     *
     * @param array{dni:string,nombre:string,email:string,telefono:string,descripcion:string,nro_cargo:string,acuse_hash:string,observacion_id:?string,usuario:string} $datos
     * @return array{id:string,creado:bool,nro_cargo:?string,acuse_hash:?string}
     */
    public function registrarSubsanacion(array $datos, ?string $id = null): array;

    /**
     * Subsanaciones del expediente, de la más reciente a la más antigua.
     *
     * @return array<int, array<string,mixed>>
     */
    public function subsanaciones(string $submissionId): array;

    /**
     * Una subsanación con los datos de su expediente (números, área y estado),
     * que es lo que necesita el acuse.
     *
     * @return array<string,mixed>|null
     */
    public function subsanacionPorId(string $id): ?array;

    /**
     * Registra la resolución del área sobre lo subsanado: 'aceptada' o
     * 'rechazada'.
     */
    public function revisar(string $subsanacionId, string $estado, string $usuario): void;
}

/**
 * Único dueño del acceso a las tablas `observaciones` y `subsanaciones`.
 */
final class ObservacionRepository implements ObservacionRepositoryInterface
{
    private DbConnection $db;

    public function __construct(?DbConnection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    public function registrar(string $submissionId, string $detalle, int $plazoDias, string $usuario): array
    {
        $id = Database::uuid();
        $fechaLimite = (new DateTimeImmutable('now'))->modify('+' . $plazoDias . ' days')->format('Y-m-d 17:00:00');

        $stmt = $this->db->prepare(
            'INSERT INTO observaciones (id, submission_id, detalle, plazo_dias, fecha_limite, estado, usuario)
             VALUES (:id, :submission_id, :detalle, :plazo_dias, :fecha_limite, \'pendiente\', :usuario)
             RETURNING id, submission_id, detalle, plazo_dias, fecha_limite, estado, usuario, created_at'
        );
        $stmt->execute([
            ':id' => $id,
            ':submission_id' => $submissionId,
            ':detalle' => $detalle,
            ':plazo_dias' => $plazoDias,
            ':fecha_limite' => $fechaLimite,
            ':usuario' => $usuario,
        ]);

        $rows = $stmt->fetchAll();
        return $rows[0] ?? ['id' => $id, 'submission_id' => $submissionId, 'detalle' => $detalle];
    }

    public function porSubmission(string $submissionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.id, o.submission_id, o.detalle, o.plazo_dias, o.fecha_limite, o.estado,
                    o.usuario, o.created_at, o.atendida_at
               FROM observaciones o
              WHERE o.submission_id = :sid
              ORDER BY o.created_at DESC'
        );
        $stmt->execute([':sid' => $submissionId]);
        return $stmt->fetchAll();
    }

    public function pendiente(string $submissionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.id, o.submission_id, o.detalle, o.plazo_dias, o.fecha_limite, o.estado,
                    o.usuario, o.created_at, o.atendida_at
               FROM observaciones o
              WHERE o.submission_id = :sid AND o.estado = \'pendiente\'
              ORDER BY o.created_at ASC
              LIMIT 1'
        );
        $stmt->execute([':sid' => $submissionId]);
        $rows = $stmt->fetchAll();
        return $rows === [] ? null : $rows[0];
    }

    public function porId(string $observacionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, submission_id, detalle, plazo_dias, fecha_limite, estado, usuario, created_at, atendida_at
               FROM observaciones
              WHERE id = :id'
        );
        $stmt->execute([':id' => $observacionId]);
        $rows = $stmt->fetchAll();
        return $rows === [] ? null : $rows[0];
    }

    public function atender(string $observacionId): void
    {
        $this->db->prepare(
            "UPDATE observaciones SET estado = 'atendida', atendida_at = NOW() WHERE id = :id"
        )->execute([':id' => $observacionId]);
    }

    public function reabrir(string $observacionId, string $nuevaFechaLimite): void
    {
        $this->db->prepare(
            "UPDATE observaciones SET estado = 'pendiente', fecha_limite = :fecha_limite, atendida_at = NULL WHERE id = :id"
        )->execute([':id' => $observacionId, ':fecha_limite' => $nuevaFechaLimite]);
    }

    public function desestimar(string $observacionId): void
    {
        $this->db->prepare(
            "UPDATE observaciones SET estado = 'atendida', atendida_at = NOW() WHERE id = :id"
        )->execute([':id' => $observacionId]);
    }

    public function registrarSubsanacion(array $datos, ?string $id = null): array
    {
        $subsanacionId = $id ?? Database::uuid();

        $stmt = $this->db->prepare(
            'INSERT INTO subsanaciones (id, submission_id, observacion_id, dni, nombre, email, telefono,
                    descripcion, nro_cargo, acuse_hash, estado, usuario)
             VALUES (:id, :submission_id, :observacion_id, :dni, :nombre, :email, :telefono,
                    :descripcion, :nro_cargo, :acuse_hash, \'registrada\', :usuario)
             ON CONFLICT (id) DO NOTHING
             RETURNING id'
        );
        $stmt->execute([
            ':id' => $subsanacionId,
            ':submission_id' => $datos['submission_id'],
            ':observacion_id' => $datos['observacion_id'] ?? null,
            ':dni' => $datos['dni'],
            ':nombre' => mb_substr((string) $datos['nombre'], 0, 200),
            ':email' => mb_substr((string) $datos['email'], 0, 255),
            ':telefono' => mb_substr((string) $datos['telefono'], 0, 20),
            ':descripcion' => $this->cifrar((string) $datos['descripcion']),
            ':nro_cargo' => $datos['nro_cargo'],
            ':acuse_hash' => $datos['acuse_hash'],
            ':usuario' => $datos['usuario'],
        ]);

        if ($stmt->fetchAll() === []) {
            // Ya existía: es un reintento con el mismo id, se devuelven sus datos
            // para responder con el mismo acuse en vez de emitir otro.
            $existente = $this->subsanacionPorId($subsanacionId);
            return [
                'id' => $subsanacionId,
                'creado' => false,
                'nro_cargo' => $existente === null ? null : (string) $existente['nro_cargo'],
                'acuse_hash' => $existente === null ? null : (string) $existente['acuse_hash'],
            ];
        }

        return [
            'id' => $subsanacionId,
            'creado' => true,
            'nro_cargo' => (string) $datos['nro_cargo'],
            'acuse_hash' => (string) $datos['acuse_hash'],
        ];
    }

    public function subsanaciones(string $submissionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT sb.id, sb.submission_id, sb.observacion_id, sb.dni, sb.nombre, sb.email, sb.telefono,
                    sb.descripcion, sb.nro_cargo, sb.acuse_hash, sb.estado, sb.usuario, sb.created_at,
                    sb.revisada_at, sb.revisada_por, o.detalle AS observacion_detalle
               FROM subsanaciones sb
               LEFT JOIN observaciones o ON o.id = sb.observacion_id
              WHERE sb.submission_id = :sid
              ORDER BY sb.created_at DESC'
        );
        $stmt->execute([':sid' => $submissionId]);

        return array_map(function (array $fila): array {
            $fila['descripcion'] = $this->descifrar((string) $fila['descripcion']);
            return $fila;
        }, $stmt->fetchAll());
    }

    public function subsanacionPorId(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT sb.id, sb.submission_id, sb.observacion_id, sb.dni, sb.nombre, sb.email, sb.telefono,
                    sb.descripcion, sb.nro_cargo, sb.acuse_hash, sb.estado, sb.usuario, sb.created_at,
                    s.nro_cargo AS nro_cargo_expediente, s.nro_expediente, s.status, s.type,
                    s.area_actual_id, a.nombre AS area_actual,
                    o.detalle AS observacion_detalle, o.fecha_limite AS observacion_fecha_limite
               FROM subsanaciones sb
               JOIN submissions s ON s.submission_id = sb.submission_id
               LEFT JOIN areas a ON a.id = s.area_actual_id
               LEFT JOIN observaciones o ON o.id = sb.observacion_id
              WHERE sb.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return null;
        }

        $fila = $rows[0];
        $fila['descripcion'] = $this->descifrar((string) $fila['descripcion']);
        return $fila;
    }

    public function revisar(string $subsanacionId, string $estado, string $usuario): void
    {
        $this->db->prepare(
            'UPDATE subsanaciones SET estado = :estado, revisada_at = NOW(), revisada_por = :usuario WHERE id = :id'
        )->execute([':estado' => $estado, ':usuario' => $usuario, ':id' => $subsanacionId]);
    }

    /**
     * La descripción de la subsanación va cifrada en reposo, igual que la del
     * alta: es texto libre del presentante y puede llevar datos personales.
     */
    private function cifrar(string $texto): string
    {
        return $texto === '' ? '' : (string) DataProtector::encrypt($texto);
    }

    /** Passthrough para los registros heredados guardados en claro. */
    private function descifrar(string $texto): string
    {
        return (string) DataProtector::decrypt($texto);
    }
}
