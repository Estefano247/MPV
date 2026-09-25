<?php

declare(strict_types=1);

require_once __DIR__ . '/Uuid.php';

/**
 * Capa HTTP compartida por los endpoints JSON.
 *
 * ApiRequest concentra los guardas de entrada (método, cuerpo JSON, parámetros
 * y formato de UUID) y ApiResponse la emisión de la respuesta. Así cada
 * endpoint queda reducido a su caso de uso, sin repetir el mismo bloque de
 * validación y salida una y otra vez.
 *
 * Todos los guardas cortan la ejecución: o devuelven el valor ya validado, o
 * emiten la respuesta de error y terminan el script.
 */
final class ApiRequest
{
    /** @var array<string,mixed>|null Cuerpo JSON, null hasta que se pide. */
    private ?array $body = null;

    /** @var array<string,mixed> */
    private array $query;

    /** @var array<string,mixed> */
    private array $form;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $form
     */
    public function __construct(array $query = [], array $form = [])
    {
        $this->query = $query;
        $this->form = $form;
    }

    /**
     * Instancia respaldada por los superglobales de la petición en curso.
     */
    public static function capture(): self
    {
        return new self($_GET, $_POST);
    }

    public function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    }

    /**
     * Corta con 405 si el método HTTP de la petición no está entre los permitidos.
     */
    public function requireMethod(string ...$methods): self
    {
        $allowed = array_map('strtoupper', $methods);
        if (!in_array($this->method(), $allowed, true)) {
            ApiResponse::error('Método no permitido', 405);
        }
        return $this;
    }

    /**
     * Cuerpo JSON de la petición. Corta con 400 si no es un objeto JSON válido.
     *
     * @return array<string,mixed>
     */
    public function body(): array
    {
        if ($this->body === null) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($decoded)) {
                ApiResponse::error('Cuerpo de solicitud inválido', 400);
            }
            $this->body = $decoded;
        }
        return $this->body;
    }

    /**
     * Parámetro de texto, buscado en el cuerpo JSON, luego $_POST y luego $_GET.
     */
    public function param(string $key, string $default = ''): string
    {
        return trim((string) ($this->raw($key) ?? $default));
    }

    /**
     * Parámetro numérico, útil para ids de áreas y similar.
     */
    public function paramInt(string $key, int $default = 0): int
    {
        $value = $this->raw($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    /**
     * Parámetro de texto acotado en longitud (motivos, comentarios, ...).
     */
    public function paramText(string $key, int $maxLength): string
    {
        return mb_substr($this->param($key), 0, $maxLength);
    }

    /**
     * Parámetro que debe ser un UUID. Corta con 400 si no lo es.
     */
    public function uuidParam(string $key): string
    {
        $value = $this->param($key);
        if (!self::isUuid($value)) {
            ApiResponse::error('ID de solicitud inválido', 400);
        }
        return $value;
    }

    public static function isUuid(string $value): bool
    {
        return Uuid::isValid($value);
    }

    /**
     * Primer valor presente para la clave en las fuentes de la petición.
     * Un null explícito en el cuerpo se trata como ausente (compatibilidad
     * con `$input[$key] ?? $otro`).
     */
    private function raw(string $key): mixed
    {
        foreach ([$this->body, $this->form, $this->query] as $source) {
            if (is_array($source) && array_key_exists($key, $source) && $source[$key] !== null) {
                return $source[$key];
            }
        }
        return null;
    }
}

/**
 * Emisión de respuestas JSON. Ambos métodos terminan la ejecución, por lo que
 * el código que sigue solo se alcanza en el camino feliz.
 */
final class ApiResponse
{
    /**
     * @param array<mixed> $data
     */
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    /**
     * Respuesta de error con el código HTTP indicado.
     */
    public static function error(string $message, int $status = 400): void
    {
        self::json(['error' => $message], $status);
    }
}
