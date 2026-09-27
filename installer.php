<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This installer must be run from the command line (CLI).\n";
    echo "Usage: php installer.php\n";
    exit(1);
}

class CliStyle {
    private static bool $supportsColor = true;

    public static function init(): void {
        self::$supportsColor = (
            DIRECTORY_SEPARATOR === '/' ||
            getenv('ANSICON') !== false ||
            getenv('ConEmuANSI') === 'ON' ||
            (defined('STDOUT') && function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT)) ||
            getenv('TERM') !== false
        );
    }

    public static function green(string $text): string {
        return self::$supportsColor ? "\033[32m{$text}\033[0m" : $text;
    }

    public static function red(string $text): string {
        return self::$supportsColor ? "\033[31m{$text}\033[0m" : $text;
    }

    public static function yellow(string $text): string {
        return self::$supportsColor ? "\033[33m{$text}\033[0m" : $text;
    }

    public static function cyan(string $text): string {
        return self::$supportsColor ? "\033[36m{$text}\033[0m" : $text;
    }

    public static function bold(string $text): string {
        return self::$supportsColor ? "\033[1m{$text}\033[0m" : $text;
    }

    public static function dim(string $text): string {
        return self::$supportsColor ? "\033[2m{$text}\033[0m" : $text;
    }
}
CliStyle::init();

function prompt(string $question, string $default = ''): string {
    $formattedPrompt = CliStyle::cyan("? ") . CliStyle::bold($question);
    if ($default !== '') {
        $formattedPrompt .= " [" . CliStyle::yellow($default) . "]";
    }
    $formattedPrompt .= ": ";

    echo $formattedPrompt;
    $input = trim((string)fgets(STDIN));
    return ($input === '') ? $default : $input;
}

function promptConfirm(string $question, bool $default = true): bool {
    $options = $default ? "[Y/n]" : "[y/N]";
    $formattedPrompt = CliStyle::cyan("? ") . CliStyle::bold($question) . " {$options}: ";

    echo $formattedPrompt;
    $input = strtolower(trim((string)fgets(STDIN)));
    if ($input === '') {
        return $default;
    }
    return in_array($input, ['y', 'yes', '1', 'true', 'o', 'oui'], true);
}

$args = array_slice($argv, 1);
$useDefaults = in_array('--defaults', $args, true) || in_array('-y', $args, true);
$showHelp = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($showHelp) {
    echo "\n" . CliStyle::bold("OpenMPS Server - Standalone CLI Installer") . "\n";
    echo CliStyle::dim("Repository: https://github.com/gabidut/OpenMPS") . "\n";
    echo "========================================\n\n";
    echo "Usage:\n";
    echo "  php installer.php [options]\n\n";
    echo "Options:\n";
    echo "  -y, --defaults    Run non-interactive setup with default values\n";
    echo "  -h, --help        Show this help message\n\n";
    exit(0);
}

echo "\n";
echo CliStyle::cyan("======================================================================") . "\n";
echo CliStyle::bold("       OpenMPS Server (DynamX Mod Protection System - v1.3.0)        ") . "\n";
echo CliStyle::dim("                https://github.com/gabidut/OpenMPS                   ") . "\n";
echo CliStyle::cyan("======================================================================") . "\n\n";

echo CliStyle::bold("Step 1: Checking System & Environment Requirements") . "\n";
echo "--------------------------------------------------\n";

$errors = [];
$warnings = [];

$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '7.4.0', '>=')) {
    echo " [" . CliStyle::green("OK") . "] PHP Version: {$phpVersion}\n";
} else {
    $errors[] = "PHP >= 7.4.0 is required. Current version is {$phpVersion}.";
    echo " [" . CliStyle::red("FAIL") . "] PHP Version: {$phpVersion} (>= 7.4.0 required)\n";
}

if (extension_loaded('openssl')) {
    echo " [" . CliStyle::green("OK") . "] Extension 'openssl' (required for AES-128 encryption)\n";
} else {
    $errors[] = "PHP extension 'openssl' is not installed or enabled.";
    echo " [" . CliStyle::red("FAIL") . "] Extension 'openssl' missing!\n";
}

if (class_exists('ZipArchive')) {
    echo " [" . CliStyle::green("OK") . "] Extension 'zip' / ZipArchive (required for pack archives)\n";
} else {
    $errors[] = "PHP extension 'zip' (ZipArchive) is not enabled in php.ini.";
    echo " [" . CliStyle::red("FAIL") . "] Extension 'zip' (ZipArchive) missing!\n";
}

if (function_exists('json_encode')) {
    echo " [" . CliStyle::green("OK") . "] Extension 'json'\n";
} else {
    $errors[] = "PHP extension 'json' is required.";
    echo " [" . CliStyle::red("FAIL") . "] Extension 'json' missing!\n";
}

$rootDir = __DIR__;
if (is_writable($rootDir)) {
    echo " [" . CliStyle::green("OK") . "] Root directory is writable: {$rootDir}\n";
} else {
    $errors[] = "Root directory {$rootDir} is not writable.";
    echo " [" . CliStyle::red("FAIL") . "] Root directory is not writable!\n";
}

$loaderFile = $rootDir . '/loader/EncryptedMPSResourceLoader.class';
if (file_exists($loaderFile)) {
    echo " [" . CliStyle::green("OK") . "] Java loader bytecode found: (" . number_format(filesize($loaderFile)) . " bytes)\n";
} else {
    echo " [" . CliStyle::cyan("INFO") . "] Core files will be installed from https://github.com/gabidut/OpenMPS\n";
}

if (!empty($errors)) {
    echo "\n" . CliStyle::red("Installation cannot continue due to missing prerequisites:") . "\n";
    foreach ($errors as $e) {
        echo "  - " . CliStyle::red($e) . "\n";
    }
    echo "\nPlease enable required extensions in your php.ini and rerun installer.php.\n\n";
    exit(1);
}

if (!empty($warnings)) {
    echo "\n" . CliStyle::yellow("Notices:") . "\n";
    foreach ($warnings as $w) {
        echo "  - " . CliStyle::yellow($w) . "\n";
    }
}

echo "\n";

echo CliStyle::bold("Step 2: Server & Security Configuration") . "\n";
echo "---------------------------------------\n";

$currentAdminUser = 'admin';
$currentAdminPass = 'admin';
$currentPort = 8081;
$currentUrl = '';
$currentKey = 'legacy';
$allowAnyKey = false;
$autoEncryptDesc = true;

if (file_exists($rootDir . '/config.php')) {
    $existingConfig = file_get_contents($rootDir . '/config.php');
    if (preg_match("/define\('ADMIN_USER',\s*'([^']+)'\)/", $existingConfig, $m)) {
        $currentAdminUser = $m[1];
    }
    if (preg_match("/define\('ADMIN_PASS',\s*'([^']+)'\)/", $existingConfig, $m)) {
        $currentAdminPass = $m[1];
    }
    if (preg_match("/define\('SERVER_PUBLIC_URL',\s*'([^']*)'\)/", $existingConfig, $m)) {
        $currentUrl = $m[1];
    }
}

$isExistingPassHashed = !empty(password_get_info($currentAdminPass)['algo']);

if ($useDefaults) {
    echo CliStyle::dim("Running in non-interactive mode (--defaults). Applying default configuration...") . "\n";
    $adminUser       = $currentAdminUser;
    if ($isExistingPassHashed) {
        $adminPassHash    = $currentAdminPass;
        $adminPassDisplay = '(existing hash kept)';
    } else {
        $adminPassHash    = password_hash($currentAdminPass, PASSWORD_DEFAULT);
        $adminPassDisplay = $currentAdminPass;
    }
    $serverPort      = $currentPort;
    $serverPublicUrl = $currentUrl;
    $accessKey       = 'legacy';
    $allowAnyKey     = false;
} else {
    echo "\n" . CliStyle::bold("1. Dashboard Web Authentication") . "\n";
    $adminUser = prompt("Admin dashboard username", $currentAdminUser);
    if ($isExistingPassHashed) {
        $passInput = prompt("Admin dashboard password (leave empty to keep current)", "");
        if ($passInput === '') {
            $adminPassHash    = $currentAdminPass;
            $adminPassDisplay = '(kept existing)';
        } else {
            $adminPassHash    = password_hash($passInput, PASSWORD_DEFAULT);
            $adminPassDisplay = $passInput;
        }
    } else {
        $adminPass = prompt("Admin dashboard password", $currentAdminPass);
        $adminPassHash    = password_hash($adminPass, PASSWORD_DEFAULT);
        $adminPassDisplay = $adminPass;
    }

    echo "\n" . CliStyle::bold("2. Network & Public URL") . "\n";
    $serverPort = (int)prompt("Local development HTTP port", (string)$currentPort);
    if ($serverPort <= 0 || $serverPort > 65535) {
        $serverPort = 8081;
    }

    $defaultUrl = $currentUrl ?: "http://localhost:{$serverPort}/";
    $serverPublicUrlInput = prompt("Server public base URL (leave empty for auto-detection)", $defaultUrl);
    $serverPublicUrl = trim($serverPublicUrlInput);
    if (!empty($serverPublicUrl)) {
        $serverPublicUrl = rtrim($serverPublicUrl, '/') . '/';
    }

    echo "\n" . CliStyle::bold("3. MPS Access Key (DynamX Security)") . "\n";
    echo "  MPS Access Key is locked to: " . CliStyle::green("legacy") . " (only legacy is authorized)\n";
    $accessKey = 'legacy';
    $allowAnyKey = false;

    $autoEncryptDesc = promptConfirm("Automatically encrypt .desc descriptors on-the-fly?", true);
}

echo "\n";

echo CliStyle::bold("Step 3: Installing Directories and Generating Configuration") . "\n";
echo "-----------------------------------------------------------\n";

$dirsToCreate = [
    $rootDir . '/packs',
    $rootDir . '/loader',
    $rootDir . '/1.3.0',
    $rootDir . '/tools',
];

foreach ($dirsToCreate as $d) {
    if (!is_dir($d)) {
        if (mkdir($d, 0777, true)) {
            echo " [" . CliStyle::green("CREATED") . "] Directory: " . basename($d) . "/\n";
        } else {
            echo " [" . CliStyle::red("FAILED") . "] Could not create directory: {$d}\n";
        }
    } else {
        echo " [" . CliStyle::green("EXISTS") . "] Directory: " . basename($d) . "/\n";
    }
}

$requiredFiles = [
    $rootDir . '/index.php',
    $rootDir . '/pack_manager.php',
    $rootDir . '/get.php',
    $rootDir . '/1.3.0/home.php',
    $rootDir . '/loader/EncryptedMPSResourceLoader.class'
];

$missingCore = false;
foreach ($requiredFiles as $f) {
    if (!file_exists($f)) {
        $missingCore = true;
        break;
    }
}

if ($missingCore) {
    echo " [" . CliStyle::cyan("DOWNLOAD") . "] Fetching OpenMPS files from https://github.com/gabidut/OpenMPS...\n";
    $zipUrl = 'https://github.com/gabidut/OpenMPS/archive/refs/heads/main.zip';
    $ctx = stream_context_create([
        'http' => [
            'user_agent' => 'OpenMPS-Installer',
            'follow_location' => 1,
            'timeout' => 30
        ]
    ]);
    $archiveData = @file_get_contents($zipUrl, false, $ctx);
    if ($archiveData === false) {
        $zipUrl = 'https://github.com/gabidut/OpenMPS/archive/refs/heads/master.zip';
        $archiveData = @file_get_contents($zipUrl, false, $ctx);
    }
    if ($archiveData !== false) {
        $tempZip = $rootDir . '/openmps_temp.zip';
        file_put_contents($tempZip, $archiveData);
        $zip = new ZipArchive();
        if ($zip->open($tempZip) === true) {
            $extractedCount = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                $slashPos = strpos($entryName, '/');
                if ($slashPos === false) continue;
                $relative = substr($entryName, $slashPos + 1);
                if ($relative === '' || substr($relative, -1) === '/') continue;
                if ($relative === 'installer.php' || $relative === 'config.php') continue;
                $destPath = $rootDir . '/' . $relative;
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0777, true);
                }
                file_put_contents($destPath, $zip->getFromIndex($i));
                $extractedCount++;
            }
            $zip->close();
            @unlink($tempZip);
            echo " [" . CliStyle::green("DOWNLOADED") . "] Installed {$extractedCount} core files from https://github.com/gabidut/OpenMPS\n";
        }
    }
}

$allowAnyKeyStr = $allowAnyKey ? 'true' : 'false';
$autoEncryptDescStr = $autoEncryptDesc ? 'true' : 'false';

$configContent = <<<PHP
<?php

define('ADMIN_USER', '%ADMIN_USER%');
// Hashed administrator password
define('ADMIN_PASS', '%ADMIN_PASS%');

define('ALLOWED_ACCESS_KEYS', [
    'legacy'
]);

define('ALLOW_ANY_VALID_KEY', false);
define('SERVER_PUBLIC_URL', '%SERVER_PUBLIC_URL%');

define('MPS_ROOT_DIR', __DIR__);
define('MPS_PACKS_DIR', MPS_ROOT_DIR . '/packs');
define('MPS_LOADER_FILE', MPS_ROOT_DIR . '/loader/EncryptedMPSResourceLoader.class');

define('AUTO_ENCRYPT_DESC_FILES', %AUTO_ENCRYPT_DESC%);

function mps_extract_aes_key(string \$accessKey): ?string {
    if (strtolower(\$accessKey) === 'legacy') {
        return 'legacy';
    }
    \$decoded = base64_decode(\$accessKey, true);
    if (\$decoded === false) {
        return null;
    }
    \$dashPos = strpos(\$decoded, '-');
    if (\$dashPos === false) {
        return null;
    }
    \$aesKey = substr(\$decoded, 0, \$dashPos);
    if (strlen(\$aesKey) !== 16) {
        return null;
    }
    return \$aesKey;
}

function mps_is_key_allowed(string \$accessKey): bool {
    if (strtolower(\$accessKey) === 'legacy') {
        return true;
    }
    if (ALLOW_ANY_VALID_KEY) {
        return mps_extract_aes_key(\$accessKey) !== null;
    }
    return in_array(\$accessKey, ALLOWED_ACCESS_KEYS, true);
}

function mps_java_b64_url_encode(string \$data): string {
    return str_replace(['+', '/'], ['-', '_'], base64_encode(\$data));
}

function mps_compute_desc_key(string \$repoUrl): string {
    \$b64Url = mps_java_b64_url_encode(\$repoUrl);
    \$len = strlen(\$b64Url);
    if (\$len < 16) {
        return str_pad(\$b64Url, 16, '0');
    }
    \$step = intdiv(\$len, 16);
    \$key = '';
    for (\$i = 0; \$i < 16; \$i++) {
        \$key .= \$b64Url[\$i * \$step];
    }
    return \$key;
}

function mps_encrypt_aes_ecb(string \$data, string \$key): string {
    return openssl_encrypt(\$data, 'AES-128-ECB', \$key, OPENSSL_RAW_DATA);
}

function mps_decrypt_aes_ecb(string \$encryptedData, string \$key) {
    return openssl_decrypt(\$encryptedData, 'AES-128-ECB', \$key, OPENSSL_RAW_DATA);
}

function mps_get_base_url(): string {
    if (!empty(SERVER_PUBLIC_URL)) {
        return rtrim(SERVER_PUBLIC_URL, '/') . '/';
    }

    \$isHttps = (
        (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') ||
        (isset(\$_SERVER['SERVER_PORT']) && \$_SERVER['SERVER_PORT'] == 443) ||
        (isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );

    \$protocol = \$isHttps ? 'https://' : 'http://';
    \$host = \$_SERVER['HTTP_HOST'] ?? 'localhost:%PORT%';

    \$requestUri = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if (preg_match('#^(.*?)(/get/.*)$#', \$requestUri, \$m)) {
        \$basePath = rtrim(\$m[1], '/') . '/';
    } elseif (preg_match('#^(.*?)(/1\.3\.0/.*)$#', \$requestUri, \$m)) {
        \$basePath = rtrim(\$m[1], '/') . '/';
    } else {
        \$basePath = rtrim(dirname(\$requestUri), '/\\\\') . '/';
    }

    if (\$basePath === '//' || empty(\$basePath)) {
        \$basePath = '/';
    }

    return \$protocol . \$host . \$basePath;
}
PHP;

$replacements = [
    '%ADMIN_USER%'        => addslashes($adminUser),
    '%ADMIN_PASS%'        => addslashes($adminPassHash),
    '%ACCESS_KEY%'        => addslashes($accessKey),
    '%ALLOW_ANY_KEY%'     => $allowAnyKeyStr,
    '%SERVER_PUBLIC_URL%' => addslashes($serverPublicUrl),
    '%AUTO_ENCRYPT_DESC%' => $autoEncryptDescStr,
    '%PORT%'              => (string)$serverPort
];

$configContent = str_replace(array_keys($replacements), array_values($replacements), $configContent);
file_put_contents($rootDir . '/config.php', $configContent);
echo " [" . CliStyle::green("SAVED") . "] Configuration file written: config.php\n";

$batFile = $rootDir . '/start_server.bat';
$batContent = <<<BAT
@echo off
title MPS Custom Server - DynamX
echo ========================================================
echo        Starting MPS Custom Server (DynamX)
echo ========================================================
echo.
echo Server local URL  : http://localhost:{$serverPort}/
echo Web Dashboard     : http://localhost:{$serverPort}/index.php
echo MPS Auth Endpoint : http://localhost:{$serverPort}/1.3.0/home.php
echo.
echo Press Ctrl+C to stop the server.
echo.
php -d upload_max_filesize=512M -d post_max_size=512M -d memory_limit=512M -S 0.0.0.0:{$serverPort} index.php
pause
BAT;
file_put_contents($batFile, $batContent . "\r\n");
echo " [" . CliStyle::green("UPDATED") . "] Windows launcher script: start_server.bat (Port {$serverPort})\n";

$shFile = $rootDir . '/start_server.sh';
$shContent = <<<SH
#!/usr/bin/env bash
echo "========================================================"
echo "       Starting MPS Custom Server (DynamX)"
echo "========================================================"
echo ""
echo "Server local URL  : http://localhost:{$serverPort}/"
echo "Web Dashboard     : http://localhost:{$serverPort}/index.php"
echo "MPS Auth Endpoint : http://localhost:{$serverPort}/1.3.0/home.php"
echo ""
echo "Press Ctrl+C to stop the server."
echo ""
php -d upload_max_filesize=512M -d post_max_size=512M -d memory_limit=512M -S 0.0.0.0:{$serverPort} index.php
SH;
file_put_contents($shFile, $shContent . "\n");
@chmod($shFile, 0755);
echo " [" . CliStyle::green("UPDATED") . "] Linux/macOS launcher script: start_server.sh (Port {$serverPort})\n";

$displayUrl = !empty($serverPublicUrl) ? $serverPublicUrl : "http://localhost:{$serverPort}/";

echo "\n";
echo CliStyle::cyan("======================================================================") . "\n";
echo CliStyle::green("         🎉 MPS SERVER INSTALLATION COMPLETED SUCCESSFULLY!          ") . "\n";
echo CliStyle::cyan("======================================================================") . "\n\n";

echo CliStyle::bold("Connection Details:") . "\n";
echo "  • Web Dashboard URL   : " . CliStyle::bold($displayUrl) . "\n";
echo "  • Admin Username      : " . CliStyle::green($adminUser) . "\n";
echo "  • Admin Password      : " . CliStyle::green($adminPassDisplay) . "\n";
echo "  • MPS Access Key      : " . CliStyle::yellow($accessKey) . " (only legacy authorized)\n";
echo "  • Repository          : " . CliStyle::cyan("https://github.com/gabidut/OpenMPS") . "\n\n";

echo CliStyle::bold("Minecraft Client Setup (MpsRepositories.json):") . "\n";
$jsonSample = json_encode([
    'mainUrl'      => $displayUrl,
    'auxUrls'      => [],
    'accessKey'    => $accessKey,
    'mpsVersion'   => '1.3.0',
    'repositories' => [
        [
            'key'     => 'westerlife',
            'version' => '1.0.0',
            'type'    => 'dir'
        ]
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

echo CliStyle::dim($jsonSample) . "\n\n";

echo CliStyle::bold("To start the server:") . "\n";
if (DIRECTORY_SEPARATOR === '\\') {
    echo "  Double-click " . CliStyle::bold("start_server.bat") . " or run:\n";
    echo "  " . CliStyle::cyan("php -d upload_max_filesize=512M -d post_max_size=512M -S 0.0.0.0:{$serverPort} index.php") . "\n";
} else {
    echo "  Execute:\n";
    echo "  " . CliStyle::cyan("./start_server.sh") . "\n";
}
echo "\n";
