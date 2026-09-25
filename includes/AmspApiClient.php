<?php

declare(strict_types=1);

/**
 * Cliente para la API de AMSP (https://amspweb.net/api).
 */
final class AmspApiClient
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Obtiene los datos del asociado por DNI y fecha de nacimiento.
     *
     * La API raíz exige dia, mes y anio de nacimiento.
     *
     * @return array<int, mixed>|null
     */
    public function getSocio(string $dni, int $dia, int $mes, int $anio): ?array
    {
        $query = http_build_query([
            'dni' => $dni,
            'dia' => $dia,
            'mes' => $mes,
            'anio' => $anio,
        ]);
        $array = $this->get($this->baseUrl . '/?' . $query);
        return $array[0] ?? null;
    }

    /**
     * Obtiene el estado de cuenta del asociado.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function getCuenta(string $codigoSocio): ?array
    {
        return $this->get($this->baseUrl . '/cuenta/?id=' . rawurlencode($codigoSocio));
    }

    /**
     * Realiza una petición GET y decodifica la respuesta JSON.
     *
     * @return array<mixed>|null
     */
    private function get(string $url): ?array
    {
        $response = $this->getWithCurl($url);
        if ($response === false) {
            $response = $this->getWithStream($url);
        }
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    private function getWithCurl(string $url): string|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'AMSP-Cliente/1.0',
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        return ($response === false || $error !== '') ? false : $response;
    }

    private function getWithStream(string $url): string|false
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'header' => "User-Agent: AMSP-Cliente/1.0\r\n",
            ],
        ]);
        return file_get_contents($url, false, $context);
    }
}
