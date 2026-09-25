<?php

declare(strict_types=1);

/**
 * S3Service independiente para /solicitudes.
 * Usa la configuraciÃ³n local (solicitudes/includes/config.php), no la del proyecto padre.
 */
final class S3Service
{
    public const ALLOWED_CONTENT_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public const MAX_FILE_SIZE = 10 * 1024 * 1024;

    private static function getConfig(): array
    {
        return require __DIR__ . '/config.php';
    }

    private static function endpoint(string $bucket, string $region): string
    {
        if (str_contains($bucket, '.')) {
            return "s3.{$region}.amazonaws.com/{$bucket}";
        }
        return "{$bucket}.s3.{$region}.amazonaws.com";
    }

    private static function randomPrefix(): string
    {
        return bin2hex(random_bytes(4));
    }

    private static function buildKey(string $submissionType, string $fileName, ?string $submissionId = null): string
    {
        $typePrefixes = [
            'afiliacion' => 'afiliacion',
            'pre-evaluacion' => 'pre-evaluacion',
            'credito' => 'credito',
            'mpv' => 'mpv',
            'auxilio-retiro' => 'auxilio-retiro',
            'auxilio-invalidez' => 'auxilio-invalidez',
            'seguro-sepelio' => 'seguro-sepelio',
            'prestamo-solidario' => 'prestamo-solidario',
            'auxilio-fallecimiento' => 'auxilio-fallecimiento',
        ];
        $folder = $typePrefixes[$submissionType] ?? 'otros';
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
        $safeName = substr((string) $safeName, 0, 200);

        $group = $submissionId;
        if ($group === null || $group === '') {
            $group = self::randomPrefix();
        }
        $group = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $group), 0, 64);

        return "uploads/{$folder}/{$group}/" . time() . "-{$safeName}";
    }

    public static function generateUploadUrl(string $fileName, string $fileType, string $submissionType = 'credito', ?int $fileSize = null, ?string $submissionId = null): array
    {
        $config = self::getConfig();
        $bucket = $config['s3']['bucketName'];
        $region = $config['aws']['region'];
        $accessKey = $config['aws']['accessKeyId'];
        $secretKey = $config['aws']['secretAccessKey'];

        if ($bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new RuntimeException('S3 no configurado: revisa AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY y S3_BUCKET_NAME en solicitudes/.env');
        }

        $key = self::buildKey($submissionType, $fileName, $submissionId);
        $host = self::endpoint($bucket, $region);
        $expires = 300;
        $now = time();
        $date = gmdate('Ymd', $now);

        $headers = ['host' => $host];
        if ($fileSize !== null) {
            $headers['content-length'] = (string) $fileSize;
        }
        // Cifrado en reposo del objeto (AES-256 administrado por AWS).
        $headers['x-amz-server-side-encryption'] = 'AES256';
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . $v . "\n";
            $signedHeaders .= ($signedHeaders ? ';' : '') . $k;
        }

        $canonicalQueryString = http_build_query([
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$accessKey}/{$date}/{$region}/s3/aws4_request",
            'X-Amz-Date' => gmdate('Ymd\THis\Z', $now),
            'X-Amz-Expires' => $expires,
            'X-Amz-SignedHeaders' => $signedHeaders,
        ]);

        $payloadHash = 'UNSIGNED-PAYLOAD';
        $algorithm = 'AWS4-HMAC-SHA256';
        $dateTime = gmdate('Ymd\THis\Z', $now);

        $canonicalRequest = "PUT\n/{$key}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $credentialScope = "{$date}/{$region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$dateTime}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $dateKey = hash_hmac('sha256', $date, "AWS4{$secretKey}", true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $url = "https://{$host}/{$key}?{$canonicalQueryString}&X-Amz-Signature={$signature}";

        // El cliente debe enviar estos encabezados en el PUT (firma SigV4 incluida).
        return [
            'url' => $url,
            'key' => $key,
            'headers' => ['x-amz-server-side-encryption' => 'AES256'],
        ];
    }

    public static function getObjectSize(string $key): ?int
    {
        $config = self::getConfig();
        $bucket = $config['s3']['bucketName'];
        $region = $config['aws']['region'];
        $accessKey = $config['aws']['accessKeyId'];
        $secretKey = $config['aws']['secretAccessKey'];

        if ($bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new RuntimeException('Credenciales S3 no configuradas (solicitudes/.env)');
        }

        $host = self::endpoint($bucket, $region);
        $now = time();
        $date = gmdate('Ymd', $now);

        $payloadHash = hash('sha256', '');
        $algorithm = 'AWS4-HMAC-SHA256';
        $dateTime = gmdate('Ymd\THis\Z', $now);

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $dateTime,
        ];

        $canonicalHeaders = '';
        $signedHeaders = '';
        ksort($headers);
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeaders .= ($signedHeaders ? ';' : '') . strtolower($k);
        }

        $canonicalRequest = "HEAD\n/{$key}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $credentialScope = "{$date}/{$region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$dateTime}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $dateKey = hash_hmac('sha256', $date, "AWS4{$secretKey}", true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = "{$algorithm} Credential={$accessKey}/{$credentialScope},SignedHeaders={$signedHeaders},Signature={$signature}";

        $ch = curl_init("https://{$host}/{$key}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_NOBODY => true,
            CURLOPT_HTTPHEADER => [
                "x-amz-content-sha256: {$payloadHash}",
                "x-amz-date: {$dateTime}",
                "Authorization: {$authorization}",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 300 || $response === false) {
            return null;
        }

        if (preg_match('/Content-Length:\s*(\d+)/i', $response, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Genera una URL GET firmada (SigV4) para descargar un objeto de S3.
     * Equivalente al `getSignedUrl` del dashboard Node (expira 60 s y fuerza
     * descarga con `response-content-disposition=attachment`).
     */
    public static function generateDownloadUrl(string $key, int $expires = 60): string
    {
        $config = self::getConfig();
        $bucket = $config['s3']['bucketName'];
        $region = $config['aws']['region'];
        $accessKey = $config['aws']['accessKeyId'];
        $secretKey = $config['aws']['secretAccessKey'];

        if ($bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new RuntimeException('S3 no configurado: revisa AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY y S3_BUCKET_NAME en solicitudes/.env');
        }

        $host = self::endpoint($bucket, $region);
        $now = time();
        $date = gmdate('Ymd', $now);
        $dateTime = gmdate('Ymd\THis\Z', $now);

        $queryParams = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$accessKey}/{$date}/{$region}/s3/aws4_request",
            'X-Amz-Date' => $dateTime,
            'X-Amz-Expires' => $expires,
            'X-Amz-SignedHeaders' => 'host',
            'response-content-disposition' => 'attachment',
        ];
        ksort($queryParams);
        $canonicalQueryString = http_build_query($queryParams);

        $canonicalRequest = "GET\n/{$key}\n{$canonicalQueryString}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
        $credentialScope = "{$date}/{$region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$dateTime}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $dateKey = hash_hmac('sha256', $date, "AWS4{$secretKey}", true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return "https://{$host}/{$key}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    public static function deleteObject(string $key): void
    {
        $config = self::getConfig();
        $bucket = $config['s3']['bucketName'];
        $region = $config['aws']['region'];
        $accessKey = $config['aws']['accessKeyId'];
        $secretKey = $config['aws']['secretAccessKey'];

        if ($bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new RuntimeException('Credenciales S3u no configuradas (solicitudes/.env)');
        }

        $host = self::endpoint($bucket, $region);
        $now = time();
        $date = gmdate('Ymd', $now);

        $payloadHash = hash('sha256', '');
        $algorithm = 'AWS4-HMAC-SHA256';
        $dateTime = gmdate('Ymd\THis\Z', $now);

        $headers = [
            'host' => $host,
            'x-amz-date' => $dateTime,
        ];

        $canonicalHeaders = '';
        $signedHeaders = '';
        ksort($headers);
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeaders .= ($signedHeaders ? ';' : '') . strtolower($k);
        }

        $canonicalRequest = "DELETE\n/{$key}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $credentialScope = "{$date}/{$region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$dateTime}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $dateKey = hash_hmac('sha256', $date, "AWS4{$secretKey}", true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = "{$algorithm} Credential={$accessKey}/{$credentialScope},SignedHeaders={$signedHeaders},Signature={$signature}";

        $ch = curl_init("https://{$host}/{$key}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                "x-amz-date: {$dateTime}",
                "Authorization: {$authorization}",
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 300) {
            error_log("Error deleting S3 file {$key}: HTTP {$httpCode}");
        }
    }
}
