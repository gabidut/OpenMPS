<?php

require_once __DIR__ . '/../config.php';

$modVersionB64 = $_GET['mod_version'] ?? '';
$target        = $_GET['target'] ?? '';

$modVersion = !empty($modVersionB64) ? base64_decode($modVersionB64) : '';

if (empty($target)) {
    http_response_code(400);
    echo "Missing target parameter.";
    exit;
}

$safeTarget = str_replace(['..', "\0", '\\'], ['', '', '/'], $target);

$targetFile = MPS_PACKS_DIR . '/' . $safeTarget;

if (!file_exists($targetFile) || is_dir($targetFile)) {
    http_response_code(404);
    echo "Target '$target' not found.";
    exit;
}

$mimeType = mime_content_type($targetFile) ?: 'application/octet-stream';
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($targetFile));

readfile($targetFile);
exit;
