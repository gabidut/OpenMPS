<?php
// OpenMPS administration API v1. Game-facing MPS access keys are NOT administration tokens.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../pack_manager.php';

class MpsApiError extends RuntimeException {
    public $status;
    public $errorCode;
    public function __construct(int $status, string $code, string $message) {
        parent::__construct($message); $this->status = $status; $this->errorCode = $code;
    }
}
function mps_api_fail(int $status, string $code, string $message): void { throw new MpsApiError($status, $code, $message); }
function mps_api_tokens_file(): string { return getenv('OPENMPS_API_TOKEN_FILE') ?: MPS_ROOT_DIR . '/api-tokens.php'; }
function mps_api_read_tokens(): array {
    $file = mps_api_tokens_file();
    if (!is_file($file)) return [];
    clearstatcache(true, $file);
    if (function_exists('opcache_invalidate')) opcache_invalidate($file, true);
    $tokens = require $file;
    return is_array($tokens) ? $tokens : [];
}
function mps_api_auth(string $scope): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer (omps_[A-Za-z0-9_-]{43})$/D', $header, $match)) mps_api_fail(401, 'unauthorized', 'An administration token is required.');
    $hash = hash('sha256', $match[1]);
    foreach (mps_api_read_tokens() as $id => $token) {
        if (empty($token['revoked']) && isset($token['hash']) && hash_equals($token['hash'], $hash)) {
            if (!in_array($scope, $token['scopes'] ?? [], true)) mps_api_fail(403, 'scope_required', 'This token cannot perform this operation.');
            return ['id' => $id, 'label' => $token['label'], 'scopes' => $token['scopes']];
        }
    }
    mps_api_fail(401, 'unauthorized', 'The administration token is invalid or revoked.');
}
function mps_api_key(string $value): string {
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $value)) mps_api_fail(400, 'invalid_pack_key', 'Pack key must use lowercase letters, digits, underscores or hyphens (1–64 characters).');
    return $value;
}
function mps_api_version(string $value): string {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $value) || substr($value, -1) === '.') mps_api_fail(400, 'invalid_version', 'Invalid pack version (1–64 portable characters).');
    return $value;
}
function mps_api_public_url(): string {
    $base = mps_get_base_url();
    if (empty(SERVER_PUBLIC_URL)) $base = preg_replace('#/api/$#', '/', $base);
    if (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true) || parse_url($base, PHP_URL_USER) !== null || parse_url($base, PHP_URL_QUERY) !== null || parse_url($base, PHP_URL_FRAGMENT) !== null) mps_api_fail(500, 'public_url', 'Configure a valid SERVER_PUBLIC_URL.');
    return rtrim($base, '/') . '/';
}
function mps_api_max_upload(): int { return max(1024, min(536870912, (int)(getenv('OPENMPS_API_MAX_UPLOAD_BYTES') ?: 536870912))); }
function mps_api_state_dir(): string {
    $path = MPS_ROOT_DIR . '/var/openmps-api';
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) mps_api_fail(500, 'state_directory', 'Cannot create API state directory.');
    return $path;
}
function mps_api_meta_path(string $key, string $version): string { return mps_api_state_dir() . '/' . hash('sha256', $key . ':' . $version) . '.json'; }
function mps_api_metadata(string $key, string $version): ?array {
    $path = mps_api_meta_path($key, $version);
    return is_file($path) ? json_decode(file_get_contents($path), true) : null;
}
function mps_api_remove_tree(string $path): void {
    if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') mps_api_remove_tree($path . '/' . $name);
    rmdir($path);
}
function mps_api_safe_path(string $name): string {
    $name = str_replace('\\', '/', $name);
    if ($name === '' || $name[0] === '/' || strpos($name, ':') !== false || preg_match('/[\x00-\x1f\x7f]/', $name) || !preg_match('//u', $name)) mps_api_fail(422, 'unsafe_path', 'Archive contains an unsafe path.');
    $parts = explode('/', rtrim($name, '/'));
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.' || preg_match('/\.(?:php[0-9]?|phtml|phar)(?:\.|$)/i', $part) || preg_match('/[<>"|?*]/', $part) || preg_match('/[. ]$/', $part) || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)) mps_api_fail(422, 'unsafe_path', 'Archive contains a non-portable path.');
    }
    return implode('/', $parts);
}
function mps_api_extract(string $archive, string $destination): void {
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) mps_api_fail(422, 'invalid_zip', 'Cannot read ZIP archive.');
    try {
        if ($zip->numFiles > 10000) mps_api_fail(422, 'zip_limit', 'Archive has too many entries.');
        $seen = []; $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); $raw = $stat['name']; $dir = substr($raw, -1) === '/'; $name = mps_api_safe_path($raw); $lower = strtolower($name);
            if (isset($seen[$lower])) mps_api_fail(422, 'duplicate_path', 'Archive contains a duplicate path.');
            $seen[$lower] = true;
            $ops = 0; $attrs = 0; $zip->getExternalAttributesIndex($i, $ops, $attrs);
            $mode = ($attrs >> 16) & 0170000;
            if ($mode === 0120000 || ($mode !== 0 && $mode !== 0100000 && $mode !== 0040000)) mps_api_fail(422, 'zip_link', 'Links and special files are not accepted.');
            if (!empty($stat['encryption_method'])) mps_api_fail(422, 'encrypted_zip', 'Encrypted source archives are not accepted.');
            if ($dir) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed = ['dynx','json','obj','mtl','png','jpg','jpeg','tga','bmp','gif','webp','gltf','glb','bin','ogg','wav','dc','lang','txt','properties'];
            if (!in_array($ext, $allowed, true)) mps_api_fail(422, 'unsupported_file', 'Unsupported pack file: ' . $name);
            if (strpos($name, 'assets/') !== 0 && $ext !== 'dynx') mps_api_fail(422, 'resource_layout', 'Put models, materials, textures and sounds under assets/<domain>/: ' . $name);
            if ($stat['size'] > 134217728) mps_api_fail(422, 'zip_limit', 'A file exceeds 128 MiB.');
            $total += $stat['size']; if ($total > 1073741824) mps_api_fail(422, 'zip_limit', 'Uncompressed archive exceeds 1 GiB.');
            $target = $destination . '/' . $name;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) mps_api_fail(422, 'path_conflict', 'Conflicting archive paths.');
            $in = $zip->getStream($raw); $out = fopen($target, 'xb');
            if (!$in || !$out) mps_api_fail(422, 'extract', 'Cannot extract pack entry.');
            try { $count = stream_copy_to_stream($in, $out, $stat['size'] + 1); } finally { fclose($in); fclose($out); }
            if ($count !== $stat['size']) mps_api_fail(422, 'zip_size', 'Archive entry size mismatch.');
        }
        if (!is_file($destination . '/pack_info.dynx')) mps_api_fail(422, 'pack_info', 'pack_info.dynx must be at the archive root.');
    } finally { $zip->close(); }
}
function mps_api_pack_info(string $file, string $key, string $version): void {
    if (filesize($file) > 8388608) mps_api_fail(422, 'pack_info', 'pack_info.dynx is too large.');
    $text = file_get_contents($file);
    foreach (['PackName' => $key, 'PackVersion' => $version] as $field => $expected) {
        preg_match_all('/^\s*' . $field . '\s*:\s*([^\r\n]*)$/m', $text, $matches);
        if (count($matches[1]) !== 1 || trim($matches[1][0]) !== $expected) mps_api_fail(422, 'metadata_mismatch', $field . ' must match the requested pack key/version.');
    }
    if (!preg_match('/^\s*DcFileVersion\s*:\s*\S+/m', $text)) mps_api_fail(422, 'pack_info', 'DcFileVersion is required.');
}
function mps_api_publish(string $archive, string $key, string $version, string $digest, string $accessKey): array {
    mps_api_key($key); mps_api_version($version);
    if (!preg_match('/^[a-f0-9]{64}$/D', $digest) || !hash_equals($digest, hash_file('sha256', $archive))) mps_api_fail(422, 'checksum', 'Archive checksum mismatch.');
    if (!mps_is_key_allowed($accessKey)) mps_api_fail(422, 'access_key', 'The game-facing MPS access key is not accepted by this server.');
    $state = mps_api_state_dir(); $lock = fopen($state . '/' . hash('sha256', $key . ':' . $version) . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) mps_api_fail(409, 'busy', 'This version is currently being published. Retry later.');
    $stage = null;
    try {
        $target = MPS_PACKS_DIR . '/' . $key . '/' . $version; $existing = mps_api_metadata($key, $version);
        if (file_exists($target)) {
            if ($existing && hash_equals($existing['source_sha256'], $digest) && hash_equals($existing['access_key_sha256'] ?? '', hash('sha256', $accessKey)) && $existing['public_url'] === mps_api_public_url() && is_file($target . '/' . $existing['client_file'])) { $existing['idempotent'] = true; return $existing; }
            mps_api_fail(409, 'version_exists', 'This version already exists. Publish a new version instead.');
        }
        $parent = dirname($target); if (!is_dir($parent) && !mkdir($parent, 0700, true)) mps_api_fail(500, 'storage', 'Cannot create pack directory.');
        if (is_link($parent)) mps_api_fail(500, 'storage', 'Pack directory must not be a symbolic link.');
        $stage = $parent . '/.api-stage-' . bin2hex(random_bytes(12)); if (!mkdir($stage, 0700)) mps_api_fail(500, 'storage', 'Cannot prepare pack directory.');
        mps_api_extract($archive, $stage); mps_api_pack_info($stage . '/pack_info.dynx', $key, $version);
        $base = mps_api_public_url(); $scan = mps_scan_pack_directory($stage, $key);
        mps_generate_desc_file($stage, $key, $scan);
        mps_generate_mps_repositories_json($stage, $key, $version, $accessKey, $base);
        $client = mps_generate_client_zip($stage, $key, $version, [], $base);
        $repo = mps_get_or_build_repo_zip($key, $version, '1.3.0', null, $stage, $base);
        if (!$client || !$repo) mps_api_fail(500, 'generation', 'Cannot generate MPS archives.');
        $metadata = ['pack_key'=>$key,'pack_version'=>$version,'source_sha256'=>$digest,'access_key_sha256'=>hash('sha256',$accessKey),'client_file'=>basename($client),'client_sha256'=>hash_file('sha256',$client),'client_size'=>filesize($client),'files_count'=>count($scan['files']),'domains'=>$scan['domains'],'public_url'=>$base,'published_at'=>gmdate('c'),'idempotent'=>false];
        $metaFile = mps_api_meta_path($key, $version); $pendingMeta = $state . '/.meta-' . bin2hex(random_bytes(12));
        if (file_put_contents($pendingMeta, json_encode($metadata, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX) === false) mps_api_fail(500,'storage','Cannot prepare publication receipt.');
        if (file_exists($target) || !rename($stage, $target)) { unlink($pendingMeta); mps_api_fail(409, 'version_exists', 'Version appeared during publication; nothing was overwritten.'); }
        $stage = null;
        if (!rename($pendingMeta,$metaFile)) { mps_api_remove_tree($target); unlink($pendingMeta); mps_api_fail(500,'storage','Cannot save publication receipt.'); }
        return $metadata;
    } finally { if ($stage !== null && is_dir($stage)) mps_api_remove_tree($stage); flock($lock, LOCK_UN); fclose($lock); }
}
function mps_api_json(array $data, int $status = 200): void {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES); exit;
}
// Editable copies contain available original resources, never credentials or generated MPS archives.
function mps_api_build_source(string $key, string $version, string $archive): void {
    $root = MPS_PACKS_DIR . '/' . $key . '/' . $version;
    if (!is_dir($root) || is_link($root) || is_link(dirname($root))) mps_api_fail(404, 'not_found', 'Pack originals not found.');
    if (!is_file($root . '/pack_info.dynx')) mps_api_fail(422, 'originals_missing', 'The server does not contain an editable pack_info.dynx.');
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) mps_api_fail(500, 'source_archive', 'Cannot prepare editable archive.');
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $seen = []; $total = 0; $count = 0;
        foreach ($iterator as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->isLink() || !$file->isFile()) mps_api_fail(422, 'unsafe_source', 'Pack originals contain a link or special file.');
            if (preg_match('#(?:^|/)\.[^/]+#', $relative)) continue;
            if (preg_match('/\.part$/i', $relative)) mps_api_fail(422, 'encrypted_originals', 'Only encrypted model files remain; an editable copy cannot be reconstructed.');
            if (preg_match('/\.(zip|dnxpack|desc)$/i', $relative) || in_array($relative, ['MpsRepositories.json', 'MpsRepositories.json.example', 'README_INSTALLATION.txt'], true)) continue;
            $name = mps_api_safe_path($relative);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['dynx','obj','mtl','png','jpg','jpeg','tga','bmp','gif','webp','gltf','glb','bin','ogg','wav','dc','json','lang','txt','properties','md'], true) && !in_array(strtolower(basename($name)), ['license','readme'], true)) mps_api_fail(422, 'unsupported_original', 'Unsupported original file: ' . $name);
            if (isset($seen[strtolower($name)])) mps_api_fail(422, 'duplicate_path', 'Pack originals contain duplicate paths.');
            $seen[strtolower($name)] = true;
            $size = $file->getSize(); $total += $size; $count++;
            if ($size > 134217728 || $total > 1073741824 || $count > 10000) mps_api_fail(422, 'source_limit', 'Pack originals exceed recovery limits.');
            if (!$zip->addFile($file->getPathname(), $name)) mps_api_fail(500, 'source_archive', 'Cannot archive an original file.');
        }
        if (!$zip->close()) mps_api_fail(500, 'source_archive', 'Cannot finish editable archive.');
        $zip = null;
        clearstatcache(true, $archive);
        if (filesize($archive) > 536870912) mps_api_fail(413, 'source_limit', 'Editable ZIP exceeds 512 MiB.');
    } finally { if ($zip !== null) $zip->close(); }
}

function mps_api_run(): void {
    $temp = null;
    try {
        $action = $_GET['action'] ?? 'capabilities'; $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $auth = mps_api_auth($action === 'publish' ? 'publish' : ($action === 'source' ? 'recover' : 'read'));
        if ($action === 'publish' && $method === 'POST') {
            $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($length <= 0 || $length > mps_api_max_upload()) mps_api_fail(413, 'upload_limit', 'Missing length or upload exceeds the server limit.');
            if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/zip') mps_api_fail(415,'content_type','Send a ZIP body with Content-Type application/zip.');
            $temp = tempnam(sys_get_temp_dir(), 'openmps-api-'); $in=fopen('php://input','rb');$out=fopen($temp,'wb');
            try { $count=stream_copy_to_stream($in,$out,$length+1); } finally {fclose($in);fclose($out);}
            if ($count !== $length) mps_api_fail(400,'body_length','Upload was incomplete.');
            $result=mps_api_publish($temp,$_SERVER['HTTP_X_OPENMPS_PACK_KEY']??'',$_SERVER['HTTP_X_OPENMPS_PACK_VERSION']??'',$_SERVER['HTTP_X_OPENMPS_SHA256']??'',$_SERVER['HTTP_X_OPENMPS_ACCESS_KEY']??'legacy');
            unlink($temp); $temp=null; mps_api_json(['success'=>true,'publication'=>$result]);
        }
        if ($method !== 'GET') mps_api_fail(405,'method','Use GET for reading or POST for publishing.');
        if ($action === 'capabilities') mps_api_json(['success'=>true,'api_version'=>1,'protocol'=>'openmps-admin/1','mps_version'=>'1.3.0','public_url'=>mps_api_public_url(),'max_upload_bytes'=>mps_api_max_upload(),'immutable_versions'=>true,'editable_download'=>true,'recovery_allowed'=>in_array('recover',$auth['scopes'],true),'scopes'=>$auth['scopes']]);
        if ($action === 'packs') {
            $packs=[];foreach(mps_get_all_packs() as $pack){$meta=mps_api_metadata($pack['key'],$pack['version']);$packs[]=['pack_key'=>$pack['key'],'pack_version'=>$pack['version'],'client_size'=>$pack['client_zip_size'],'client_sha256'=>$meta['client_sha256']??null,'files_count'=>$pack['files_count']];}
            mps_api_json(['success'=>true,'packs'=>$packs]);
        }
        if ($action === 'source') {
            $key = mps_api_key($_GET['pack_key'] ?? '');
            $version = mps_api_version($_GET['pack_version'] ?? '');
            $temp = tempnam(sys_get_temp_dir(), 'openmps-source-');
            $cleanupSource = $temp;
            register_shutdown_function(function () use ($cleanupSource) { if (is_file($cleanupSource)) unlink($cleanupSource); });
            mps_api_build_source($key, $version, $temp);
            header('Content-Type: application/zip');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="source-' . $key . '-' . $version . '.zip"');
            header('Content-Length: ' . filesize($temp));
            header('X-OpenMPS-SHA256: ' . hash_file('sha256', $temp));
            header('X-OpenMPS-Pack-Key: ' . $key);
            header('X-OpenMPS-Pack-Version: ' . $version);
            readfile($temp);
            unlink($temp);
            exit;
        }
        if ($action === 'client') {
            $key=mps_api_key($_GET['pack_key']??'');$version=mps_api_version($_GET['pack_version']??'');$file=MPS_PACKS_DIR.'/'.$key.'/'.$version.'/client-'.$key.'-'.$version.'.zip';
            if (!is_file($file)||is_link($file)) mps_api_fail(404,'not_found','Client archive not found.');
            header('Content-Type: application/zip');header('Cache-Control: no-store');header('Content-Disposition: attachment; filename="client-'.$key.'-'.$version.'.zip"');header('Content-Length: '.filesize($file));header('X-OpenMPS-SHA256: '.hash_file('sha256',$file));readfile($file);exit;
        }
        mps_api_fail(404,'not_found','Unknown API action.');
    } catch (MpsApiError $e) {
        if($temp!==null&&is_file($temp))unlink($temp);
        mps_api_json(['success'=>false,'code'=>$e->errorCode,'error'=>$e->getMessage()],$e->status);
    } catch (Throwable $e) {
        if($temp!==null&&is_file($temp))unlink($temp);
        error_log('OpenMPS API internal error: '.get_class($e));
        mps_api_json(['success'=>false,'code'=>'internal_error','error'=>'Server operation failed; check server logs.'],500);
    }
}
