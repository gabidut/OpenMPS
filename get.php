<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pack_manager.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$mpsVersion = $_GET['mps_version'] ?? '1.3.0';
$packKey    = $_GET['pack'] ?? '';
$b64Version = $_GET['version'] ?? '';
$filePath   = $_GET['file'] ?? '';

if (empty($packKey) && isset($_SERVER['REQUEST_URI'])) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#(?:^|/)get/([^/]+)/([^/]+)/([^/]+)(?:/(.*))?$#', $path, $matches)) {
        $mpsVersion = $matches[1];
        $packKey    = $matches[2];
        $b64Version = $matches[3];
        $filePath   = $matches[4] ?? '';
    }
}

$packKey    = urldecode(trim($packKey));
$b64Version = urldecode(trim($b64Version));
$filePath   = urldecode(trim($filePath));

if (empty($packKey) || empty($b64Version)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing parameters: mps_version, pack, or version.";
    exit;
}

$normalizedB64 = str_replace(['-', '_'], ['+', '/'], $b64Version);
$packVersion = base64_decode($normalizedB64, true);

if ($packVersion === false || empty($packVersion)) {
    $packVersion = $b64Version;
    $b64Version  = base64_encode($packVersion);
}

$packDir = MPS_PACKS_DIR . '/' . $packKey . '/' . $packVersion;
if (!is_dir($packDir)) {
    $found = false;
    if (is_dir(MPS_PACKS_DIR)) {
        foreach (scandir(MPS_PACKS_DIR) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (strcasecmp($dir, $packKey) === 0) {
                $checkVer = MPS_PACKS_DIR . '/' . $dir . '/' . $packVersion;
                if (is_dir($checkVer)) {
                    $packDir = $checkVer;
                    $found = true;
                    break;
                }
            }
        }
    }
    if (!$found) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Pack or version not found: " . htmlspecialchars($packKey . ' ' . $packVersion);
        exit;
    }
}

$cleanPath = str_replace(['..', "\0", '\\'], ['', '', '/'], $filePath);
$cleanPath = ltrim($cleanPath, '/');

$isRepoRequest = (
    empty($cleanPath) ||
    $cleanPath === 'repo.zip' ||
    $cleanPath === 'repository.zip' ||
    preg_match('#^custom(?:/[^/]+)?$#i', $cleanPath) ||
    (preg_match('#^[^/]+/[^/]+$#', $cleanPath) && !file_exists($packDir . '/' . $cleanPath))
);

if ($isRepoRequest) {
    $repoZip = mps_get_or_build_repo_zip($packKey, $packVersion, $mpsVersion, $b64Version);
    if ($repoZip && file_exists($repoZip)) {
        $baseUrl = mps_get_base_url();
        $repoUrl = rtrim($baseUrl, '/') . '/get/' . $mpsVersion . '/' . $packKey . '/' . $b64Version;
        $descKey = mps_compute_desc_key($repoUrl);

        header('Content-Type: application/java-archive');
        header('Content-Length: ' . filesize($repoZip));
        header('Cache-Control: public, max-age=60');
        header('X-MPS-Repo-Url: ' . $repoUrl);
        header('X-MPS-Desc-Key: ' . $descKey);
        readfile($repoZip);
        exit;
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unable to generate repository archive for pack: " . htmlspecialchars($packKey);
    exit;
}

$safeFilePath = preg_replace('#^custom/[^/]+/#i', '', $cleanPath);
$targetFile = $packDir . '/' . $safeFilePath;

if (!file_exists($targetFile) || is_dir($targetFile)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Resource not found: " . htmlspecialchars($safeFilePath);
    exit;
}

$isDescFile = (substr(strtolower($safeFilePath), -5) === '.desc');

if ($isDescFile) {
    $content = file_get_contents($targetFile);
    $isPlainText = (strpos($content, 'Id=') !== false || strpos($content, 'ResourcesDomains=') !== false);

    if ($isPlainText && AUTO_ENCRYPT_DESC_FILES) {
        $baseUrl = mps_get_base_url();
        $repoUrl = rtrim($baseUrl, '/') . '/get/' . $mpsVersion . '/' . $packKey . '/' . $b64Version;
        $descKey = mps_compute_desc_key($repoUrl);
        $encryptedDesc = mps_encrypt_aes_ecb($content, $descKey);

        if ($encryptedDesc !== false) {
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . strlen($encryptedDesc));
            header('Cache-Control: public, max-age=3600');
            header('X-MPS-Repo-Url: ' . $repoUrl);
            header('X-MPS-Desc-Key: ' . $descKey);
            echo $encryptedDesc;
            exit;
        }
    }
}

$ext = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
$mimeTypes = [
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'obj'   => 'text/plain',
    'mtl'   => 'text/plain',
    'json'  => 'application/json',
    'ogg'   => 'audio/ogg',
    'desc'  => 'application/octet-stream',
    'class' => 'application/java-vm',
    'txt'   => 'text/plain',
    'dynx'  => 'text/plain'
];

$mimeType = $mimeTypes[$ext] ?? (mime_content_type($targetFile) ?: 'application/octet-stream');

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($targetFile));
header('Cache-Control: public, max-age=86400');
header('Accept-Ranges: bytes');

readfile($targetFile);
exit;
