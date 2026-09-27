<?php

require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$accessKey = $_GET['access_key'] ?? '';

if (empty($accessKey)) {
    http_response_code(400);
    echo "MPS request status : 400;Missing access_key parameter;";
    exit;
}

if (!mps_is_key_allowed($accessKey)) {
    http_response_code(403);
    echo "MPS request status : 403;Access Denied: Invalid access key;";
    exit;
}

if (strtolower($accessKey) === 'legacy') {
    http_response_code(200);
    echo "MPS request status : 200;legacy;";
    exit;
}

$aesKey = mps_extract_aes_key($accessKey);
if ($aesKey === null) {
    http_response_code(400);
    echo "MPS request status : 400;Invalid access key format (expected 16-char AES key before dash);";
    exit;
}

if (!file_exists(MPS_LOADER_FILE)) {
    http_response_code(500);
    echo "MPS request status : 500;Missing EncryptedMPSResourceLoader.class file on server;";
    exit;
}

$loaderBytecode = file_get_contents(MPS_LOADER_FILE);
if ($loaderBytecode === false || strlen($loaderBytecode) === 0) {
    http_response_code(500);
    echo "MPS request status : 500;Could not read loader class file;";
    exit;
}

$encryptedBytes = mps_encrypt_aes_ecb($loaderBytecode, $aesKey);
if ($encryptedBytes === false) {
    http_response_code(500);
    echo "MPS request status : 500;Encryption error;";
    exit;
}

$base64Payload = base64_encode($encryptedBytes);

echo "MPS request status : 200;" . $base64Payload . ";";
exit;
