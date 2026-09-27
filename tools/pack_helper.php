<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../pack_manager.php';

if ($argc < 2) {
    echo "=== MPS CLI Tool (DynamX Pack Helper) ===\n";
    echo "Usage:\n";
    echo "  php pack_helper.php import-zip <file.zip> [pack_id] [version]\n";
    echo "  php pack_helper.php generate-key [suffix]\n";
    echo "  php pack_helper.php scan-pack <pack_folder> [pack_id]\n";
    echo "  php pack_helper.php encrypt-desc <file.desc> <repo_url> [output]\n";
    echo "  php pack_helper.php test-key <access_key_base64>\n";
    exit(1);
}

$action = $argv[1];

switch ($action) {
    case 'import-zip':
        $zipFile = $argv[2] ?? null;
        $packId  = $argv[3] ?? null;
        $version = $argv[4] ?? null;

        if (!$zipFile || !file_exists($zipFile)) {
            echo "Error: Please specify an existing .zip file.\n";
            echo "Usage: php pack_helper.php import-zip <file.zip> [pack_id] [version]\n";
            exit(1);
        }

        echo "Importing archive: $zipFile\n";
        $res = mps_process_pack_zip($zipFile, $packId, $version);
        if ($res['success']) {
            echo "Pack successfully imported and generated!\n";
            echo "--------------------------------------------------------\n";
            echo "  - Identifier     : " . $res['packKey'] . "\n";
            echo "  - Version        : " . $res['packVersion'] . "\n";
            echo "  - Mapped Files   : " . $res['filesCount'] . "\n";
            echo "  - Domains        : " . implode(', ', $res['domains']) . "\n";
            echo "  - Descriptor     : " . $res['descFile'] . "\n";
            echo "  - Client Pack    : " . $res['clientZip'] . "\n";
            echo "  - Location       : " . $res['targetDir'] . "\n";
            echo "--------------------------------------------------------\n";
        } else {
            echo "Error during import: " . $res['error'] . "\n";
            exit(1);
        }
        break;

    case 'generate-key':
        $suffix = $argv[2] ?? 'CUSTOM';
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $aesKey = '';
        for ($i = 0; $i < 16; $i++) {
            $aesKey .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $rawKey = $aesKey . '-' . strtoupper($suffix) . '-' . random_int(1, 99);
        $b64Key = base64_encode($rawKey);

        echo "New key generated successfully!\n";
        echo "--------------------------------------------------------\n";
        echo "AES-128 Key (16 bytes)   : $aesKey\n";
        echo "Plaintext Key            : $rawKey\n";
        echo "MPS Access Key (Base64)  : $b64Key\n";
        echo "--------------------------------------------------------\n";
        echo "Add to config.php:\n";
        echo "  '$b64Key',\n";
        echo "\nPlace into your MpsRepositories.json:\n";
        echo "  \"accessKey\": \"$b64Key\"\n";
        break;

    case 'scan-pack':
        $packDir = $argv[2] ?? null;
        if (!$packDir || !is_dir($packDir)) {
            echo "Error: Please specify a valid directory.\n";
            exit(1);
        }
        $packDir = rtrim(str_replace('\\', '/', realpath($packDir)), '/');
        $packId  = $argv[3] ?? basename($packDir);

        echo "Scanning pack in: $packDir\n";
        $scan = mps_scan_pack_directory($packDir, $packId);
        $descFile = mps_generate_desc_file($packDir, $packId, $scan);
        mps_generate_mps_repositories_json($packDir, $packId, '1.0.0');
        mps_generate_client_zip($packDir, $packId, '1.0.0');

        echo "Descriptor file generated successfully:\n  -> $descFile\n";
        echo "Total mapped files   : " . count($scan['files']) . "\n";
        echo "Resource domains     : " . implode(', ', $scan['domains']) . "\n";
        break;

    case 'encrypt-desc':
        $file = $argv[2] ?? null;
        $url  = $argv[3] ?? null;
        $out  = $argv[4] ?? ($file . '.encrypted');

        if (!$file || !file_exists($file) || !$url) {
            echo "Usage: php pack_helper.php encrypt-desc <file.desc> <repo_url> [output]\n";
            exit(1);
        }

        $key = mps_compute_desc_key($url);
        $plain = file_get_contents($file);
        $encrypted = mps_encrypt_aes_ecb($plain, $key);

        file_put_contents($out, $encrypted);
        echo "Encrypted .desc file generated at: $out\n";
        echo "AES key calculated for URL: $key\n";
        break;

    case 'test-key':
        $key = $argv[2] ?? '';
        $aes = mps_extract_aes_key($key);
        if ($aes) {
            echo "VALID Key!\n";
            echo "AES-128 Key: $aes (" . strlen($aes) . " bytes)\n";
            echo "Allowed on this server: " . (mps_is_key_allowed($key) ? "YES" : "NO") . "\n";
        } else {
            echo "INVALID Key: Non-compliant format.\n";
        }
        break;

    default:
        echo "Unknown action: $action\n";
        break;
}
