<?php

define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin');

define('ALLOWED_ACCESS_KEYS', [
    'legacy'
]);

define('ALLOW_ANY_VALID_KEY', false);
define('SERVER_PUBLIC_URL', '');

define('MPS_ROOT_DIR', __DIR__);
define('MPS_PACKS_DIR', MPS_ROOT_DIR . '/packs');
define('MPS_LOADER_FILE', MPS_ROOT_DIR . '/loader/EncryptedMPSResourceLoader.class');

define('AUTO_ENCRYPT_DESC_FILES', true);

function mps_extract_aes_key(string $accessKey): ?string {
    if (strtolower($accessKey) === 'legacy') {
        return 'legacy';
    }
    $decoded = base64_decode($accessKey, true);
    if ($decoded === false) {
        return null;
    }
    $dashPos = strpos($decoded, '-');
    if ($dashPos === false) {
        return null;
    }
    $aesKey = substr($decoded, 0, $dashPos);
    if (strlen($aesKey) !== 16) {
        return null;
    }
    return $aesKey;
}

function mps_is_key_allowed(string $accessKey): bool {
    if (strtolower($accessKey) === 'legacy') {
        return true;
    }
    if (ALLOW_ANY_VALID_KEY) {
        return mps_extract_aes_key($accessKey) !== null;
    }
    return in_array($accessKey, ALLOWED_ACCESS_KEYS, true);
}

function mps_java_b64_url_encode(string $data): string {
    return str_replace(['+', '/'], ['-', '_'], base64_encode($data));
}

function mps_compute_desc_key(string $repoUrl): string {
    $b64Url = mps_java_b64_url_encode($repoUrl);
    $len = strlen($b64Url);
    if ($len < 16) {
        return str_pad($b64Url, 16, '0');
    }
    $step = intdiv($len, 16);
    $key = '';
    for ($i = 0; $i < 16; $i++) {
        $key .= $b64Url[$i * $step];
    }
    return $key;
}

function mps_encrypt_aes_ecb(string $data, string $key): string {
    return openssl_encrypt($data, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
}

function mps_decrypt_aes_ecb(string $encryptedData, string $key) {
    return openssl_decrypt($encryptedData, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
}

function mps_get_base_url(): string {
    if (!empty(SERVER_PUBLIC_URL)) {
        return rtrim(SERVER_PUBLIC_URL, '/') . '/';
    }

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );

    $protocol = $isHttps ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8081';

    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if (preg_match('#^(.*?)(/get/.*)$#', $requestUri, $m)) {
        $basePath = rtrim($m[1], '/') . '/';
    } elseif (preg_match('#^(.*?)(/1\.3\.0/.*)$#', $requestUri, $m)) {
        $basePath = rtrim($m[1], '/') . '/';
    } else {
        $basePath = rtrim(dirname($requestUri), '/\\') . '/';
    }

    if ($basePath === '//' || empty($basePath)) {
        $basePath = '/';
    }

    return $protocol . $host . $basePath;
}