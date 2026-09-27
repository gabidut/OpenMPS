<?php

require_once __DIR__ . '/config.php';

function mps_scan_pack_directory(string $packDir, string $packKey): array {
    $packDir = rtrim(str_replace('\\', '/', realpath($packDir)), '/');
    if (!is_dir($packDir)) {
        return ['files' => [], 'domains' => []];
    }

    $files = [];
    $domains = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($packDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            continue;
        }

        $fullPath = str_replace('\\', '/', $item->getPathname());
        $relPath  = substr($fullPath, strlen($packDir) + 1);

        $baseName = basename($relPath);
        if (
            strpos($relPath, '__MACOSX') !== false ||
            $baseName === '.DS_Store' ||
            $baseName === 'Thumbs.db' ||
            $baseName === 'desktop.ini' ||
            strpos($relPath, '.git') !== false
        ) {
            continue;
        }

        if (strpos($relPath, '/') === false) {
            if (
                preg_match('#\.(desc|example)$#i', $baseName) ||
                preg_match('#^(client|repo|\.repo)-.*\.zip$#i', $baseName) ||
                preg_match('#^MpsRepositories.*\.json$#i', $baseName) ||
                $baseName === 'LISEZMOI_INSTALLATION.txt' ||
                $baseName === 'README_INSTALLATION.txt' ||
                $baseName === 'README.txt'
            ) {
                continue;
            }
        }

        if (preg_match('#^assets/([^/]+)/#i', $relPath, $m)) {
            $domains[strtolower($m[1])] = true;
        }

        $files[] = $relPath;
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    $domainList = array_keys($domains);
    if (empty($domainList)) {
        $domainList = [strtolower($packKey)];
    }

    return [
        'files'   => $files,
        'domains' => $domainList
    ];
}

function mps_generate_desc_file(string $packDir, string $packKey, array $scanData): string {
    $descPath = rtrim($packDir, '/\\') . '/' . $packKey . '.desc';

    $domains = array_unique(array_merge($scanData['domains'], [strtolower($packKey)]));
    $domainString = implode(' ', $domains);

    $lines = [];
    $lines[] = "Id=" . $packKey;
    $lines[] = "ResourcesDomains=" . $domainString;

    foreach ($scanData['files'] as $f) {
        $lines[] = "$f=0";
    }

    $content = implode("\n", $lines) . "\n";
    file_put_contents($descPath, $content);

    return $descPath;
}

function mps_generate_mps_repositories_json(
    string $packDir,
    string $packKey,
    string $packVersion,
    ?string $accessKey = 'legacy',
    ?string $mainUrl = null,
    string $type = 'url'
): string {
    if (empty($accessKey)) {
        $accessKey = 'legacy';
    }
    if (empty($mainUrl)) {
        $mainUrl = mps_get_base_url();
    }

    $data = [
        'mainUrl'      => $mainUrl,
        'auxUrls'      => [],
        'accessKey'    => $accessKey,
        'mpsVersion'   => '1.3.0',
        'repositories' => [
            [
                'key'     => $packKey,
                'version' => $packVersion,
                'type'    => $type
            ]
        ]
    ];

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    $jsonFile    = rtrim($packDir, '/\\') . '/MpsRepositories.json';
    $exampleFile = rtrim($packDir, '/\\') . '/MpsRepositories.json.example';

    file_put_contents($jsonFile, $json);
    file_put_contents($exampleFile, $json);

    return $json;
}

function mps_generate_client_zip(
    string $packDir,
    string $packKey,
    string $packVersion,
    array $extraFiles = []
): ?string {
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $packInfoFile = rtrim($packDir, '/\\') . '/pack_info.dynx';
    if (!file_exists($packInfoFile)) {
        $packInfoContent = "PackName: {$packKey}\r\n"
                         . "CompatibleWithLoaderVersions: [1.0,1.1)\r\n"
                         . "PackVersion: {$packVersion}\r\n"
                         . "DcFileVersion: 12.5.0\r\n";
        file_put_contents($packInfoFile, $packInfoContent);
    } else {
        $existing = file_get_contents($packInfoFile);
        if (strpos($existing, 'DcFileVersion:') === false) {
            $existing = rtrim($existing) . "\r\nDcFileVersion: 12.5.0\r\n";
            file_put_contents($packInfoFile, $existing);
        }
    }

    $clientZipPath = rtrim($packDir, '/\\') . '/client-' . $packKey . '-' . $packVersion . '.zip';
    if (file_exists($clientZipPath)) {
        @unlink($clientZipPath);
    }

    $zip = new ZipArchive();
    if ($zip->open($clientZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }

    $jsonFile = rtrim($packDir, '/\\') . '/MpsRepositories.json';
    if (file_exists($jsonFile)) {
        $zip->addFile($jsonFile, 'MpsRepositories.json');
    }

    if (file_exists($packInfoFile)) {
        $zip->addFile($packInfoFile, 'pack_info.dynx');
    }

    $included = ['MpsRepositories.json' => true, 'pack_info.dynx' => true];

    foreach ($extraFiles as $relName => $fullPath) {
        if (file_exists($fullPath) && is_file($fullPath)) {
            $zip->addFile($fullPath, $relName);
            $included[$relName] = true;
        }
    }

    $normalizedPackDir = rtrim(str_replace('\\', '/', $packDir), '/');
    if (is_dir($normalizedPackDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($normalizedPackDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $fullPath = str_replace('\\', '/', $item->getPathname());
            $relPath  = substr($fullPath, strlen($normalizedPackDir) + 1);
            $baseName = basename($relPath);

            if (
                strpos($relPath, '__MACOSX') !== false ||
                $baseName === '.DS_Store' ||
                $baseName === 'Thumbs.db' ||
                strpos($relPath, '.git') !== false
            ) {
                continue;
            }

            if (strpos($relPath, 'assets/') === 0) {
                continue;
            }

            if (
                preg_match('#\.(desc|example)$#i', $baseName) ||
                preg_match('#^(client|repo|\.repo)-.*\.zip$#i', $baseName) ||
                $baseName === 'MpsRepositories.json' ||
                $baseName === 'pack_info.dynx' ||
                $baseName === 'LISEZMOI_INSTALLATION.txt' ||
                $baseName === 'README_INSTALLATION.txt' ||
                $baseName === 'README.txt'
            ) {
                continue;
            }

            if (!isset($included[$relPath])) {
                $zip->addFile($fullPath, $relPath);
                $included[$relPath] = true;
            }
        }
    }

    $mainUrl = mps_get_base_url();
    $readme = "=== DynamX / WesterLife MPS Client Pack ===\r\n"
            . "Pack       : {$packKey}\r\n"
            . "Version    : {$packVersion}\r\n"
            . "MPS Server : {$mainUrl}\r\n\r\n"
            . "INSTALLATION:\r\n"
            . "1. Place this .zip file (or extracted directory) into your game folder:\r\n"
            . "   .minecraft/DynamX/\r\n"
            . "2. Launch Minecraft! 3D models, textures, and sounds will be automatically\r\n"
            . "   streamed and protected dynamically from the MPS server.\r\n";
    $zip->addFromString('README_INSTALLATION.txt', $readme);

    $zip->close();

    return $clientZipPath;
}

function mps_get_or_build_repo_zip(string $packKey, string $packVersion, string $mpsVersion = '1.3.0', ?string $b64Version = null): ?string {
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $packDir = MPS_PACKS_DIR . '/' . $packKey . '/' . $packVersion;
    if (!is_dir($packDir)) {
        return null;
    }

    if (empty($b64Version)) {
        $b64Version = base64_encode($packVersion);
    }

    $repoZipPath = $packDir . '/repo-' . $packKey . '-' . $packVersion . '.zip';

    $scan = mps_scan_pack_directory($packDir, $packKey);
    $descPath = mps_generate_desc_file($packDir, $packKey, $scan);
    $plainDesc = file_get_contents($descPath);

    $baseUrl = mps_get_base_url();
    $repoUrl = rtrim($baseUrl, '/') . '/get/' . $mpsVersion . '/' . $packKey . '/' . $b64Version;
    $descKey = mps_compute_desc_key($repoUrl);
    $encryptedDesc = mps_encrypt_aes_ecb($plainDesc, $descKey);

    $zip = new ZipArchive();
    if ($zip->open($repoZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }

    $zip->addFromString($packKey . '.desc', $encryptedDesc);

    foreach ($scan['files'] as $relFile) {
        $fullPath = $packDir . '/' . $relFile;
        if (file_exists($fullPath) && is_file($fullPath)) {
            $zip->addFile($fullPath, $relFile);
        }
    }

    $zip->close();

    return $repoZipPath;
}

function mps_process_pack_zip(
    string $zipFilePath,
    ?string $packKey = null,
    ?string $packVersion = null,
    ?string $accessKey = null
): array {
    if (!file_exists($zipFilePath)) {
        return ['success' => false, 'error' => "ZIP file not found."];
    }
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'error' => "PHP ZipArchive extension is not installed."];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFilePath) !== true) {
        return ['success' => false, 'error' => "Unable to open ZIP archive."];
    }

    $entries = [];
    $hasDesc = null;
    $descContent = null;
    $hasJson = null;
    $jsonContent = null;
    $hasPackInfo = null;
    $packInfoContent = null;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = str_replace('\\', '/', $stat['name']);
        $entries[] = $name;

        if (preg_match('#(?:^|/)([^/]+\.desc)$#i', $name)) {
            $hasDesc = $name;
            $descContent = $zip->getFromIndex($i);
        }
        if (preg_match('#(?:^|/)MpsRepositories\.json$#i', $name)) {
            $hasJson = $name;
            $jsonContent = $zip->getFromIndex($i);
        }
        if (preg_match('#(?:^|/)pack_info\.dynx$#i', $name)) {
            $hasPackInfo = $name;
            $packInfoContent = $zip->getFromIndex($i);
        }
    }

    $commonRoot = '';
    $rootCandidates = [];
    foreach ($entries as $e) {
        $clean = ltrim($e, '/');
        if (strpos($clean, '__MACOSX') === 0 || basename($clean) === '.DS_Store' || basename($clean) === 'Thumbs.db') {
            continue;
        }
        $parts = explode('/', $clean);
        if (count($parts) > 1) {
            $rootCandidates[$parts[0]] = true;
        } elseif (!empty($clean) && substr($clean, -1) !== '/') {
            $rootCandidates['__ROOT_FILE__'] = true;
        }
    }

    if (count($rootCandidates) === 1 && !isset($rootCandidates['__ROOT_FILE__'])) {
        $candidate = array_key_first($rootCandidates);
        if (strtolower($candidate) !== 'assets') {
            $commonRoot = $candidate . '/';
        }
    }

    $packKey = trim($packKey ?? '');
    if (empty($packKey)) {
        if ($jsonContent) {
            $parsedJson = @json_decode($jsonContent, true);
            if (!empty($parsedJson['repositories'][0]['key'])) {
                $packKey = $parsedJson['repositories'][0]['key'];
            }
        }
        if (empty($packKey) && !empty($packInfoContent)) {
            if (preg_match('/^PackName\s*:\s*(.+)$/m', $packInfoContent, $m)) {
                $packKey = trim($m[1]);
            }
        }
        if (empty($packKey) && $descContent) {
            if (preg_match('/^Id\s*=\s*(.+)$/m', $descContent, $m)) {
                $packKey = trim($m[1]);
            }
        }
        if (empty($packKey) && !empty($commonRoot)) {
            $rootClean = rtrim($commonRoot, '/');
            $rootClean = preg_replace('/[_\-\.](v?\d+(?:\.\d+)*.*)$/i', '', $rootClean);
            $packKey = $rootClean;
        }
        if (empty($packKey)) {
            $baseZip = pathinfo($zipFilePath, PATHINFO_FILENAME);
            $baseZip = preg_replace('/[_\-\.](v?\d+(?:\.\d+)*.*)$/i', '', $baseZip);
            $cleanBase = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseZip), '_'));
            $genericNames = ['pack', 'packs', 'archive', 'assets', 'download', 'upload', 'master', 'main'];
            if (!empty($cleanBase) && !in_array($cleanBase, $genericNames, true)) {
                $packKey = $cleanBase;
            }
        }
        if (empty($packKey)) {
            $foundDomains = [];
            foreach ($entries as $e) {
                $sub = $commonRoot ? substr($e, strlen($commonRoot)) : $e;
                if (preg_match('#^assets/([^/]+)/#i', $sub, $m)) {
                    $foundDomains[strtolower($m[1])] = true;
                }
            }
            foreach ($foundDomains as $dom => $_) {
                if ($dom !== 'dynamx' && $dom !== 'dynamxmod') {
                    $packKey = $dom;
                    break;
                }
            }
            if (empty($packKey) && !empty($foundDomains)) {
                $packKey = array_key_first($foundDomains);
            }
        }
    }

    $packKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $packKey);
    $packKey = strtolower(trim($packKey, '_'));
    if (empty($packKey)) {
        $packKey = 'pack_' . date('Ymd_His');
    }

    $packVersion = trim($packVersion ?? '');
    if (empty($packVersion)) {
        if ($jsonContent) {
            $parsedJson = @json_decode($jsonContent, true);
            if (!empty($parsedJson['repositories'][0]['version'])) {
                $packVersion = $parsedJson['repositories'][0]['version'];
            }
        }
        if (empty($packVersion) && !empty($packInfoContent)) {
            if (preg_match('/^PackVersion\s*:\s*(.+)$/m', $packInfoContent, $m)) {
                $packVersion = trim($m[1]);
            }
        }
        if (empty($packVersion)) {
            $baseZip = pathinfo($zipFilePath, PATHINFO_FILENAME);
            if (preg_match('/[_\-vV]?(\d+\.\d+(?:\.\d+)?(?:[_\-\.][a-zA-Z0-9]+)?)/', $baseZip, $m)) {
                $packVersion = $m[1];
            }
        }
        if (empty($packVersion)) {
            $packVersion = '1.0.0';
        }
    }

    $packVersion = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $packVersion);
    $packVersion = trim($packVersion, '_');
    if (empty($packVersion)) {
        $packVersion = '1.0.0';
    }

    if (empty($accessKey)) {
        $accessKey = 'legacy';
    }

    $targetDir = MPS_PACKS_DIR . '/' . $packKey . '/' . $packVersion;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true)) {
        $zip->close();
        return ['success' => false, 'error' => "Unable to create destination folder: $targetDir"];
    }

    $extractedCount = 0;
    $extraNonAssetFiles = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = str_replace('\\', '/', $stat['name']);

        if (
            strpos($name, '__MACOSX') !== false ||
            basename($name) === '.DS_Store' ||
            basename($name) === 'Thumbs.db' ||
            strpos($name, '.git') !== false
        ) {
            continue;
        }

        $relPath = $name;
        if ($commonRoot !== '' && strpos($relPath, $commonRoot) === 0) {
            $relPath = substr($relPath, strlen($commonRoot));
        }
        $relPath = ltrim($relPath, '/');
        if (empty($relPath)) {
            continue;
        }

        if (strpos($relPath, '..') !== false || strpos($relPath, "\0") !== false) {
            continue;
        }

        $destFile = $targetDir . '/' . $relPath;

        if (substr($relPath, -1) === '/') {
            if (!is_dir($destFile)) {
                mkdir($destFile, 0777, true);
            }
            continue;
        }

        if (strpos($relPath, '/') === false) {
            if (
                preg_match('#\.(desc|example)$#i', $relPath) ||
                preg_match('#^client-.*\.zip$#i', $relPath)
            ) {
                continue;
            }
        }

        $parentDir = dirname($destFile);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0777, true);
        }

        $fileStream = $zip->getStream($stat['name']);
        if ($fileStream) {
            $outStream = fopen($destFile, 'wb');
            if ($outStream) {
                stream_copy_to_stream($fileStream, $outStream);
                fclose($outStream);
                $extractedCount++;

                if (strpos($relPath, 'assets/') !== 0 && strpos($relPath, 'MpsRepositories') === false) {
                    $extraNonAssetFiles[$relPath] = $destFile;
                }
            }
            fclose($fileStream);
        }
    }
    $zip->close();

    $packInfoPath = $targetDir . '/pack_info.dynx';
    if (!file_exists($packInfoPath)) {
        $packInfoData = "PackName: {$packKey}\r\n"
                      . "CompatibleWithLoaderVersions: [1.0,1.1)\r\n"
                      . "PackVersion: {$packVersion}\r\n"
                      . "DcFileVersion: 12.5.0\r\n";
        file_put_contents($packInfoPath, $packInfoData);
        $extraNonAssetFiles['pack_info.dynx'] = $packInfoPath;
    } else {
        $existing = file_get_contents($packInfoPath);
        if (strpos($existing, 'DcFileVersion:') === false) {
            $existing = rtrim($existing) . "\r\nDcFileVersion: 12.5.0\r\n";
            file_put_contents($packInfoPath, $existing);
        }
        $extraNonAssetFiles['pack_info.dynx'] = $packInfoPath;
    }

    $scanData = mps_scan_pack_directory($targetDir, $packKey);
    $descPath = mps_generate_desc_file($targetDir, $packKey, $scanData);

    $jsonContent = mps_generate_mps_repositories_json($targetDir, $packKey, $packVersion, $accessKey);

    $clientZipPath = mps_generate_client_zip($targetDir, $packKey, $packVersion, $extraNonAssetFiles);
    $repoZipPath   = mps_get_or_build_repo_zip($packKey, $packVersion);

    return [
        'success'        => true,
        'packKey'        => $packKey,
        'packVersion'    => $packVersion,
        'filesCount'     => count($scanData['files']),
        'domains'        => $scanData['domains'],
        'descFile'       => basename($descPath),
        'clientZip'      => $clientZipPath ? basename($clientZipPath) : null,
        'clientZipPath'  => $clientZipPath,
        'accessKey'      => $accessKey,
        'targetDir'      => $targetDir,
        'message'        => "Pack '{$packKey}' (version {$packVersion}) successfully extracted and configured!"
    ];
}

function mps_delete_pack(string $packKey, string $packVersion): bool {
    $packKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $packKey);
    $packVersion = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $packVersion);

    if (empty($packKey) || empty($packVersion)) {
        return false;
    }

    $versionDir = MPS_PACKS_DIR . '/' . $packKey . '/' . $packVersion;
    if (!is_dir($versionDir)) {
        return false;
    }

    $deleteDir = function(string $dir) use (&$deleteDir): bool {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $deleteDir($path) : @unlink($path);
        }
        return @rmdir($dir);
    };

    $deleted = $deleteDir($versionDir);

    $parentDir = MPS_PACKS_DIR . '/' . $packKey;
    if (is_dir($parentDir)) {
        $remaining = array_diff(scandir($parentDir), ['.', '..']);
        if (empty($remaining)) {
            @rmdir($parentDir);
        }
    }

    return $deleted;
}

function mps_get_all_packs(): array {
    $packs = [];
    if (!is_dir(MPS_PACKS_DIR)) {
        return $packs;
    }

    $packFolders = scandir(MPS_PACKS_DIR);
    foreach ($packFolders as $packFolder) {
        if ($packFolder === '.' || $packFolder === '..') continue;
        $packPath = MPS_PACKS_DIR . '/' . $packFolder;
        if (!is_dir($packPath)) continue;

        $versions = scandir($packPath);
        foreach ($versions as $v) {
            if ($v === '.' || $v === '..') continue;
            $vPath = $packPath . '/' . $v;
            if (!is_dir($vPath)) continue;

            $descFile      = $vPath . '/' . $packFolder . '.desc';
            $jsonFile      = $vPath . '/MpsRepositories.json';
            $clientZipFile = $vPath . '/client-' . $packFolder . '-' . $v . '.zip';

            $scan = mps_scan_pack_directory($vPath, $packFolder);

            $packs[] = [
                'key'             => $packFolder,
                'version'         => $v,
                'b64_version'     => base64_encode($v),
                'has_desc'        => file_exists($descFile),
                'desc_size'       => file_exists($descFile) ? filesize($descFile) : 0,
                'has_json'        => file_exists($jsonFile),
                'has_client_zip'  => file_exists($clientZipFile),
                'client_zip_name' => file_exists($clientZipFile) ? basename($clientZipFile) : null,
                'client_zip_size' => file_exists($clientZipFile) ? filesize($clientZipFile) : 0,
                'files_count'     => count($scan['files']),
                'domains'         => $scan['domains'],
                'path'            => $vPath
            ];
        }
    }

    usort($packs, function($a, $b) {
        $c = strcmp($a['key'], $b['key']);
        return $c === 0 ? strcmp($a['version'], $b['version']) : $c;
    });

    return $packs;
}

function mps_process_zips_in_packs_dir(): array {
    $results = [];
    if (!is_dir(MPS_PACKS_DIR)) {
        return $results;
    }

    $files = scandir(MPS_PACKS_DIR);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $filePath = MPS_PACKS_DIR . '/' . $file;
        if (is_file($filePath) && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'zip') {
            $res = mps_process_pack_zip($filePath);
            if ($res['success']) {
                @unlink($filePath);
                $results[] = $res;
            }
        }
    }

    return $results;
}
