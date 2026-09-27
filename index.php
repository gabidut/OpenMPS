<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pack_manager.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (preg_match('#/get/([^/]+)/([^/]+)/([^/]+)(?:/(.*))?$#', $uri, $matches)) {
    $_GET['mps_version'] = $matches[1];
    $_GET['pack']        = $matches[2];
    $_GET['version']     = $matches[3];
    $_GET['file']        = $matches[4] ?? '';
    require __DIR__ . '/get.php';
    exit;
}

if (preg_match('#/(?:1\.3\.0/)?home\.php$#', $uri)) {
    require __DIR__ . '/1.3.0/home.php';
    exit;
}

if (preg_match('#/(?:1\.3\.0/)?router\.php$#', $uri)) {
    require __DIR__ . '/1.3.0/router.php';
    exit;
}

$potentialFile = __DIR__ . $uri;
if (file_exists($potentialFile) && is_file($potentialFile) && !preg_match('#\.(php|htaccess)$#', $potentialFile)) {
    return false;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'logout') {
    unset($_SESSION['mps_authenticated']);
    session_destroy();
    header('Location: ' . mps_get_base_url());
    exit;
}

$loginError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $user = trim($_POST['username'] ?? '');
    $pass = trim($_POST['password'] ?? '');

    $isHash = !empty(password_get_info(ADMIN_PASS)['algo']);
    $passValid = $isHash ? password_verify($pass, ADMIN_PASS) : hash_equals((string)ADMIN_PASS, (string)$pass);

    if (hash_equals((string)ADMIN_USER, (string)$user) && $passValid) {
        $_SESSION['mps_authenticated'] = true;
        header('Location: ' . mps_get_base_url());
        exit;
    } else {
        $loginError = 'Invalid username or password.';
    }
}

if (empty($_SESSION['mps_authenticated'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - MPS Server</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
        <div class="card shadow-sm" style="max-width: 400px; width: 100%;">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <i class="bi bi-shield-lock-fill text-primary display-4"></i>
                    <h3 class="fw-bold mt-2">OMPS Server</h3>
                    <p class="text-muted small">Please sign in to access the dashboard</p>
                </div>
                <?php if ($loginError): ?>
                    <div class="alert alert-danger py-2 small" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($loginError) ?>
                    </div>
                <?php endif; ?>
                <form method="POST" action="index.php">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <label for="username" class="form-label small fw-semibold">Username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control" id="username" name="username" required autofocus placeholder="admin">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label small fw-semibold">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key"></i></span>
                            <input type="password" class="form-control" id="password" name="password" required placeholder="admin">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                    </button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if ($action === 'download_client') {
    $packKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['pack'] ?? '');
    $packVersion = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_GET['version'] ?? '');
    $packDir = MPS_PACKS_DIR . '/' . $packKey . '/' . $packVersion;
    $clientZip = $packDir . '/client-' . $packKey . '-' . $packVersion . '.zip';

    if (!file_exists($clientZip) && is_dir($packDir)) {
        mps_generate_client_zip($packDir, $packKey, $packVersion);
    }

    if (file_exists($clientZip)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="client-' . $packKey . '-' . $packVersion . '.zip"');
        header('Content-Length: ' . filesize($clientZip));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($clientZip);
        exit;
    } else {
        http_response_code(404);
        echo "Client archive not found for this pack.";
        exit;
    }
}

if ($action === 'regenerate') {
    $packKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['pack'] ?? '');
    $packVersion = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_GET['version'] ?? '');
    $packDir = MPS_PACKS_DIR . '/' . $packKey . '/' . $packVersion;

    if (is_dir($packDir)) {
        $scan = mps_scan_pack_directory($packDir, $packKey);
        mps_generate_desc_file($packDir, $packKey, $scan);
        mps_generate_mps_repositories_json($packDir, $packKey, $packVersion);
        mps_generate_client_zip($packDir, $packKey, $packVersion);
        $_SESSION['flash_success'] = "Pack '{$packKey}' (v{$packVersion}) regenerated successfully! (" . count($scan['files']) . " resource(s) mapped)";
    } else {
        $_SESSION['flash_error'] = "Pack '{$packKey}' (v{$packVersion}) not found.";
    }
    header('Location: ' . mps_get_base_url() . '?tab=packs');
    exit;
}

if ($action === 'delete') {
    $packKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['pack'] ?? '');
    $packVersion = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_GET['version'] ?? '');

    if (mps_delete_pack($packKey, $packVersion)) {
        $_SESSION['flash_success'] = "Pack '{$packKey}' (v{$packVersion}) was deleted.";
    } else {
        $_SESSION['flash_error'] = "Error deleting pack '{$packKey}'.";
    }
    header('Location: ' . mps_get_base_url() . '?tab=packs');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_pack') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
              (!empty($_POST['ajax']) && $_POST['ajax'] === '1');

    if (empty($_FILES) && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
        $msg = "File exceeds PHP post_max_size (" . ini_get('post_max_size') . ").";
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        }
        $_SESSION['flash_error'] = $msg;
        header('Location: ' . mps_get_base_url() . '?tab=import');
        exit;
    }

    if (!isset($_FILES['pack_zip']) || $_FILES['pack_zip']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => "File exceeds PHP upload_max_filesize (" . ini_get('upload_max_filesize') . ").",
            UPLOAD_ERR_FORM_SIZE  => "File exceeds maximum size allowed by the form.",
            UPLOAD_ERR_PARTIAL    => "File was only partially uploaded.",
            UPLOAD_ERR_NO_FILE    => "No file was selected.",
            UPLOAD_ERR_NO_TMP_DIR => "Missing temporary upload folder on server.",
            UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk.",
            UPLOAD_ERR_EXTENSION  => "A PHP extension blocked the upload."
        ];
        $errCode = $_FILES['pack_zip']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg  = $uploadErrors[$errCode] ?? "Upload error (code $errCode).";

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $errMsg]);
            exit;
        }
        $_SESSION['flash_error'] = $errMsg;
        header('Location: ' . mps_get_base_url() . '?tab=import');
        exit;
    }

    $uploadedFile = $_FILES['pack_zip']['tmp_name'];
    $originalName = $_FILES['pack_zip']['name'];
    $packKey      = !empty($_POST['pack_key']) ? trim($_POST['pack_key']) : null;
    $packVersion  = !empty($_POST['pack_version']) ? trim($_POST['pack_version']) : null;
    $accessKey    = !empty($_POST['access_key']) ? trim($_POST['access_key']) : null;

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'zip' && $ext !== 'dnxpack') {
        $msg = "Invalid format. Only .zip and .dnxpack archives are supported.";
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        }
        $_SESSION['flash_error'] = $msg;
        header('Location: ' . mps_get_base_url() . '?tab=import');
        exit;
    }

    $tempZipPath = sys_get_temp_dir() . '/' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $originalName);
    move_uploaded_file($uploadedFile, $tempZipPath);

    $res = mps_process_pack_zip($tempZipPath, $packKey, $packVersion, $accessKey);
    @unlink($tempZipPath);

    if ($res['success']) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }
        $_SESSION['flash_success'] = $res['message'];
        $_SESSION['last_imported_pack'] = $res;
    } else {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }
        $_SESSION['flash_error'] = $res['error'] ?? "Error processing pack.";
    }

    header('Location: ' . mps_get_base_url() . '?tab=import');
    exit;
}

$autoZips = mps_process_zips_in_packs_dir();
if (!empty($autoZips)) {
    $autoNames = array_map(function($p) { return $p['packKey'] . ' (v' . $p['packVersion'] . ')'; }, $autoZips);
    $_SESSION['flash_success'] = "Pack(s) auto-detected and generated from packs/ folder: " . implode(', ', $autoNames);
}

$loaderPresent = file_exists(MPS_LOADER_FILE);
$loaderSize    = $loaderPresent ? filesize(MPS_LOADER_FILE) : 0;
$baseUrl       = mps_get_base_url();
$packs         = mps_get_all_packs();

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
$lastPack     = $_SESSION['last_imported_pack'] ?? null;

unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['last_imported_pack']);

$sampleAccessKey = 'legacy';
$uploadMaxSize   = ini_get('upload_max_filesize');
$postMaxSize     = ini_get('post_max_size');

$activeTab = $_GET['tab'] ?? 'import';
if (!in_array($activeTab, ['import', 'packs', 'tech'], true)) {
    $activeTab = 'import';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OMPS Server - OpenMPS Pack Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container-fluid">
    <div class="row min-vh-100">

        <aside class="col-12 col-md-3 col-xl-2 bg-dark text-white p-3 d-flex flex-column">
            <div class="d-flex align-items-center mb-3 mb-md-4 text-white text-decoration-none">
                <i class="bi bi-shield-lock-fill fs-2 text-primary me-2"></i>
                <div>
                    <h5 class="fw-bold mb-0">OMPS Server</h5>
                    <small class="text-white-50">OpenMPS Server</small>
                </div>
            </div>

            <div class="mb-3 d-flex gap-2">
                <span class="badge bg-primary">v1.3.0</span>
            </div>

            <hr class="text-secondary my-2">

            <ul class="nav nav-pills flex-column mb-auto" id="sidebarTabs" role="tablist">
                <li class="nav-item mb-1" role="presentation">
                    <button class="nav-link w-100 text-start text-white <?= ($activeTab === 'import') ? 'active' : '' ?>"
                            id="tab-import-btn"
                            data-bs-toggle="pill"
                            data-bs-target="#tab-import"
                            type="button"
                            role="tab"
                            aria-controls="tab-import"
                            aria-selected="<?= ($activeTab === 'import') ? 'true' : 'false' ?>">
                        <i class="bi bi-cloud-arrow-up me-2"></i>Import Pack
                    </button>
                </li>
                <li class="nav-item mb-1" role="presentation">
                    <button class="nav-link w-100 text-start text-white d-flex justify-content-between align-items-center <?= ($activeTab === 'packs') ? 'active' : '' ?>"
                            id="tab-packs-btn"
                            data-bs-toggle="pill"
                            data-bs-target="#tab-packs"
                            type="button"
                            role="tab"
                            aria-controls="tab-packs"
                            aria-selected="<?= ($activeTab === 'packs') ? 'true' : 'false' ?>">
                        <span><i class="bi bi-folder2-open me-2"></i>Available Packs</span>
                        <span class="badge bg-secondary rounded-pill"><?= count($packs) ?></span>
                    </button>
                </li>
                <li class="nav-item mb-1" role="presentation">
                    <button class="nav-link w-100 text-start text-white <?= ($activeTab === 'tech') ? 'active' : '' ?>"
                            id="tab-tech-btn"
                            data-bs-toggle="pill"
                            data-bs-target="#tab-tech"
                            type="button"
                            role="tab"
                            aria-controls="tab-tech"
                            aria-selected="<?= ($activeTab === 'tech') ? 'true' : 'false' ?>">
                        <i class="bi bi-code-slash me-2"></i>Technical Data
                    </button>
                </li>
            </ul>

            <hr class="text-secondary my-3">

            <div class="d-flex align-items-center justify-content-between pt-2">
                <div class="d-flex align-items-center text-white-50 small">
                    <i class="bi bi-person-circle fs-5 me-2 text-white"></i>
                    <div>
                        <div class="text-white fw-semibold"><?= htmlspecialchars(ADMIN_USER) ?></div>
                        <div class="text-white-50" style="font-size: 11px;">Administrator</div>
                    </div>
                </div>
                <a href="?action=logout" class="btn btn-sm btn-outline-danger" title="Sign out">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </aside>

        <main class="col-12 col-md-9 col-xl-10 p-4">

            <?php if ($flashSuccess): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-check-circle-fill fs-4 me-3"></i>
                    <div>
                        <strong>Operation successful!</strong>
                        <div><?= htmlspecialchars($flashSuccess) ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                    <div>
                        <strong>Error:</strong>
                        <div><?= htmlspecialchars($flashError) ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (str_contains($baseUrl, 'localhost')): ?>
                <div class="alert alert-warning alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                    <div>
                        <strong>Notice:</strong> Server is running on a local address: <code><?= htmlspecialchars($baseUrl) ?></code>. For players connecting remotely, configure your public URL in <code>config.php</code>.
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="tab-content" id="sidebarTabsContent">

                <div class="tab-pane fade <?= ($activeTab === 'import') ? 'show active' : '' ?>" id="tab-import" role="tabpanel" aria-labelledby="tab-import-btn">

                    <?php if ($lastPack && !empty($lastPack['success'])): ?>
                        <div class="card border-success shadow-sm mb-4">
                            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-check2-circle me-2"></i>Pack "<?= htmlspecialchars($lastPack['packKey']) ?>" Ready for Minecraft!
                                </h5>
                                <a href="?action=download_client&pack=<?= urlencode($lastPack['packKey']) ?>&version=<?= urlencode($lastPack['packVersion']) ?>" class="btn btn-light btn-sm fw-semibold">
                                    <i class="bi bi-download me-1"></i>Download Client Pack (.zip)
                                </a>
                            </div>
                            <div class="card-body">
                                <p class="card-text mb-2">All required files have been automatically created and configured:</p>
                                <ul class="list-unstyled mb-0 ms-2">
                                    <li><i class="bi bi-check text-success me-1"></i><strong><?= (int)$lastPack['filesCount'] ?> resources</strong> extracted and indexed.</li>
                                    <li><i class="bi bi-check text-success me-1"></i>Detected domains: <strong><?= htmlspecialchars(implode(', ', $lastPack['domains'])) ?></strong></li>
                                    <li><i class="bi bi-check text-success me-1"></i>Descriptor generated: <code><?= htmlspecialchars($lastPack['descFile']) ?></code></li>
                                    <li><i class="bi bi-check text-success me-1"></i>Configuration created: <code>MpsRepositories.json</code></li>
                                    <li><i class="bi bi-check text-success me-1"></i>Client archive prepared: <code><?= htmlspecialchars($lastPack['clientZip']) ?></code></li>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-cloud-arrow-up text-primary me-2"></i>Import Pack (.zip) &amp; Auto-Generate
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">
                                Drag and drop your pack archive below (<code>.zip</code> or <code>.dnxpack</code>).
                                The server will automatically unpack assets, detect domains, generate the encrypted <code>.desc</code> descriptor,
                                create the <code>MpsRepositories.json</code> client configuration, and package the client-ready archive.
                            </p>

                            <form id="uploadForm" action="index.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload_pack">
                                <input type="file" id="fileInput" name="pack_zip" accept=".zip,.dnxpack" class="d-none">

                                <div id="dropzone" class="border border-2 border-primary rounded p-5 text-center bg-white mb-3" style="cursor: pointer;">
                                    <div class="display-4 text-primary mb-2">
                                        <i class="bi bi-cloud-arrow-up"></i>
                                    </div>
                                    <h5 class="fw-bold mb-1">Drag and drop your pack .zip here</h5>
                                    <p class="text-muted small mb-0">or click to browse a file (.zip, .dnxpack)</p>
                                </div>

                                <div id="fileChosenInfo" class="alert alert-info d-none d-flex justify-content-between align-items-center mb-3">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-file-earmark-zip fs-4 me-2 text-primary"></i>
                                        <div>
                                            <strong id="chosenFileName" class="text-dark">pack.zip</strong>
                                            <span id="chosenFileSize" class="text-muted small ms-2"></span>
                                        </div>
                                    </div>
                                    <button type="button" id="btnRemoveFile" class="btn btn-outline-secondary btn-sm">Change</button>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label for="pack_key" class="form-label small fw-semibold">Pack Identifier (Optional)</label>
                                        <input type="text" id="pack_key" name="pack_key" class="form-control" placeholder="Auto-detected if empty">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="pack_version" class="form-label small fw-semibold">Version (Optional)</label>
                                        <input type="text" id="pack_version" name="pack_version" class="form-control" placeholder="1.0.0 by default">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="access_key" class="form-label small fw-semibold">MPS Access Key</label>
                                        <select id="access_key" name="access_key" class="form-select">
                                            <?php foreach (ALLOWED_ACCESS_KEYS as $k): ?>
                                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div id="progressBox" class="mb-3 d-none">
                                    <div class="progress" style="height: 18px;">
                                        <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <div id="progressText" class="text-center small text-muted mt-1">Uploading and processing in progress...</div>
                                </div>

                                <div class="text-end">
                                    <button type="submit" id="btnSubmit" class="btn btn-primary px-4 py-2 fw-semibold">
                                        <i class="bi bi-lightning-charge me-1"></i>Import &amp; Generate Everything
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?= ($activeTab === 'packs') ? 'show active' : '' ?>" id="tab-packs" role="tabpanel" aria-labelledby="tab-packs-btn">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-folder2-open text-primary me-2"></i>Available Packs &amp; Resources (<?= count($packs) ?>)
                            </h5>
                            <span class="text-muted small">Storage directory: <code>mps/packs/</code></span>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($packs)): ?>
                                <div class="text-center text-muted py-5">
                                    <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                                    <p class="mb-0">No hosted packs found. Go to the <strong>Import Pack</strong> tab to upload your first pack!</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Identifier</th>
                                                <th>Version</th>
                                                <th>Resources</th>
                                                <th>Domains</th>
                                                <th>Descriptor (.desc)</th>
                                                <th class="text-end pe-3">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($packs as $p): ?>
                                            <tr>
                                                <td>
                                                    <strong class="text-primary"><?= htmlspecialchars($p['key']) ?></strong>
                                                </td>
                                                <td>
                                                    <code><?= htmlspecialchars($p['version']) ?></code>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?= (int)$p['files_count'] ?> file(s)</span>
                                                </td>
                                                <td>
                                                    <?php foreach ($p['domains'] as $dom): ?>
                                                        <span class="badge bg-dark me-1"><?= htmlspecialchars($dom) ?></span>
                                                    <?php endforeach; ?>
                                                </td>
                                                <td>
                                                    <?php if ($p['has_desc']): ?>
                                                        <span class="badge bg-success">Generated</span>
                                                        <a href="get/1.3.0/<?= urlencode($p['key']) ?>/<?= urlencode($p['b64_version']) ?>/<?= urlencode($p['key']) ?>.desc" target="_blank" class="small ms-1 text-decoration-none">
                                                            <i class="bi bi-box-arrow-up-right"></i> Test
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Missing</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="?action=download_client&pack=<?= urlencode($p['key']) ?>&version=<?= urlencode($p['version']) ?>" class="btn btn-success" title="Download Minecraft Client Pack archive (.zip)">
                                                            <i class="bi bi-download me-1"></i>Client Pack (.zip)
                                                        </a>
                                                        <a href="?action=regenerate&pack=<?= urlencode($p['key']) ?>&version=<?= urlencode($p['version']) ?>" class="btn btn-outline-primary" title="Recompute .desc descriptor and MpsRepositories.json">
                                                            <i class="bi bi-arrow-repeat me-1"></i>Regenerate
                                                        </a>
                                                        <a href="?action=delete&pack=<?= urlencode($p['key']) ?>&version=<?= urlencode($p['version']) ?>" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to permanently delete pack <?= htmlspecialchars($p['key']) ?> (v<?= htmlspecialchars($p['version']) ?>)?');" title="Delete pack">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?= ($activeTab === 'tech') ? 'show active' : '' ?>" id="tab-tech" role="tabpanel" aria-labelledby="tab-tech-btn">

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-gear-wide-connected text-primary me-2"></i>Minecraft Client Configuration (MpsRepositories.json)
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">
                                The <code>MpsRepositories.json</code> file is automatically included inside every client archive (<code>client-*.zip</code>).
                                If you configure your DynamX pack manually, place this file at the root of your pack archive inside <code>.minecraft/DynamX/</code>:
                            </p>

                            <?php
                            $selectedPackKey = !empty($packs) ? $packs[0]['key'] : 'my_pack';
                            $selectedVersion = !empty($packs) ? $packs[0]['version'] : '1.0.0';
                            $previewJson = json_encode([
                                'mainUrl'      => $baseUrl,
                                'auxUrls'      => [],
                                'accessKey'    => $sampleAccessKey,
                                'mpsVersion'   => '1.3.0',
                                'repositories' => [
                                    [
                                        'key'     => $selectedPackKey,
                                        'version' => $selectedVersion,
                                        'type'    => 'url'
                                    ]
                                ]
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            ?>

                            <div class="position-relative">
                                <pre class="bg-dark text-light p-3 rounded mb-0"><code id="jsonPreview"><?= htmlspecialchars($previewJson) ?></code></pre>
                                <button type="button" id="copyBtn" class="btn btn-sm btn-outline-light position-absolute top-0 end-0 m-2" onclick="copyConfig()">
                                    <i class="bi bi-clipboard me-1"></i>Copy
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-hdd-network text-primary me-2"></i>API Endpoints &amp; MPS Protocol
                            </h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-start px-0">
                                    <div class="ms-2 me-auto">
                                        <div class="fw-bold">Authentication &amp; Loader</div>
                                        <code class="text-break"><?= htmlspecialchars($baseUrl) ?>1.3.0/home.php?access_key=<?= urlencode($sampleAccessKey) ?></code>
                                    </div>
                                    <span class="badge bg-primary rounded-pill">GET</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-start px-0">
                                    <div class="ms-2 me-auto">
                                        <div class="fw-bold">Resource Distribution Format</div>
                                        <code class="text-break"><?= htmlspecialchars($baseUrl) ?>get/1.3.0/&lt;pack&gt;/&lt;version_base64&gt;/&lt;file_path&gt;</code>
                                    </div>
                                    <span class="badge bg-primary rounded-pill">GET</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-start px-0">
                                    <div class="ms-2 me-auto">
                                        <div class="fw-bold">Descriptor Encryption</div>
                                        <span class="text-muted small">Automatic on-the-fly AES-128 ECB encryption using keys derived from the repository URL.</span>
                                    </div>
                                    <span class="badge bg-success rounded-pill">Active</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-header bg-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-info-circle text-primary me-2"></i>Server Environment &amp; Information
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered mb-0">
                                    <tbody>
                                        <tr>
                                            <th class="w-25 bg-light">Public Base URL</th>
                                            <td><code><?= htmlspecialchars($baseUrl) ?></code></td>
                                        </tr>
                                        <tr>
                                            <th class="bg-light">PHP Version</th>
                                            <td><?= htmlspecialchars(PHP_VERSION) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="bg-light">Upload Limit (upload_max_filesize)</th>
                                            <td><?= htmlspecialchars($uploadMaxSize) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="bg-light">Post Limit (post_max_size)</th>
                                            <td><?= htmlspecialchars($postMaxSize) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="bg-light">Java Loader File</th>
                                            <td>
                                                <?php if ($loaderPresent): ?>
                                                    <span class="badge bg-success">Present</span>
                                                    <span class="text-muted small ms-2">(<?= number_format($loaderSize) ?> bytes)</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Missing</span>
                                                    <span class="text-muted small ms-2">(<code><?= htmlspecialchars(MPS_LOADER_FILE) ?></code>)</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="bg-light">Configured Access Keys</th>
                                            <td>
                                                <?php foreach (ALLOWED_ACCESS_KEYS as $k): ?>
                                                    <span class="badge bg-secondary me-1 font-monospace"><?= htmlspecialchars($k) ?></span>
                                                <?php endforeach; ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>

    const sidebarTabs = document.querySelectorAll('#sidebarTabs button[data-bs-toggle="pill"]');
    sidebarTabs.forEach(btn => {
        btn.addEventListener('shown.bs.tab', e => {
            const targetId = e.target.getAttribute('data-bs-target').replace('#tab-', '');
            history.replaceState(null, null, '?tab=' + targetId);
        });
    });

    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('fileInput');
    const fileChosenInfo = document.getElementById('fileChosenInfo');
    const chosenFileName = document.getElementById('chosenFileName');
    const chosenFileSize = document.getElementById('chosenFileSize');
    const btnRemoveFile = document.getElementById('btnRemoveFile');
    const uploadForm = document.getElementById('uploadForm');
    const progressBox = document.getElementById('progressBox');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const btnSubmit = document.getElementById('btnSubmit');

    if (dropzone && fileInput) {
        dropzone.addEventListener('click', () => fileInput.click());

        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.remove('bg-white');
            dropzone.classList.add('bg-light');
        });

        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('bg-light');
            dropzone.classList.add('bg-white');
        });

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('bg-light');
            dropzone.classList.add('bg-white');
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                handleFileSelect(e.dataTransfer.files[0]);
            }
        });

        fileInput.addEventListener('change', () => {
            if (fileInput.files && fileInput.files.length > 0) {
                handleFileSelect(fileInput.files[0]);
            }
        });
    }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' bytes';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function handleFileSelect(file) {
        const lowerName = file.name.toLowerCase();
        if (!lowerName.endsWith('.dnxpack') && !lowerName.endsWith('.zip')) {
            alert('Please select an archive in .zip or .dnxpack format.');
            return;
        }

        const dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;

        chosenFileName.textContent = file.name;
        chosenFileSize.textContent = '(' + formatBytes(file.size) + ')';
        fileChosenInfo.classList.remove('d-none');
        dropzone.classList.add('d-none');

        const packKeyInput = document.getElementById('pack_key');
        if (packKeyInput && !packKeyInput.value) {
            let base = file.name.replace(/\.(zip|dnxpack)$/i, '');
            base = base.replace(/[_\-\.](v?\d+(?:\.\d+)*.*)$/i, '');
            base = base.toLowerCase().replace(/[^a-z0-9_\-]/g, '_');
            packKeyInput.placeholder = 'Suggested: ' + base;
        }
    }

    if (btnRemoveFile) {
        btnRemoveFile.addEventListener('click', () => {
            fileInput.value = '';
            fileChosenInfo.classList.add('d-none');
            dropzone.classList.remove('d-none');
        });
    }

    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            if (!fileInput.files || fileInput.files.length === 0) {
                e.preventDefault();
                alert('Please select or drop a .zip file to import.');
                return;
            }

            e.preventDefault();
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Processing...';
            progressBox.classList.remove('d-none');
            progressBar.style.width = '0%';
            progressText.textContent = 'Uploading .zip file in progress...';

            const formData = new FormData(uploadForm);
            formData.append('ajax', '1');

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'index.php', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    progressBar.style.width = percent + '%';
                    if (percent >= 100) {
                        progressText.textContent = 'Extracting, analyzing models and generating required files...';
                    } else {
                        progressText.textContent = 'Uploading: ' + percent + '% (' + formatBytes(e.loaded) + ' / ' + formatBytes(e.total) + ')';
                    }
                }
            });

            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const resp = JSON.parse(xhr.responseText);
                        if (resp.success) {
                            progressBar.style.width = '100%';
                            progressText.textContent = 'Completed successfully! Reloading...';
                            window.location.href = 'index.php?tab=import';
                        } else {
                            alert('Error: ' + (resp.error || 'Failed to process pack.'));
                            resetUploadUi();
                        }
                    } catch(err) {
                        window.location.href = 'index.php?tab=import';
                    }
                } else {
                    alert('HTTP error ' + xhr.status + ' during upload.');
                    resetUploadUi();
                }
            };

            xhr.onerror = function() {
                alert('A network error occurred during upload.');
                resetUploadUi();
            };

            xhr.send(formData);
        });
    }

    function resetUploadUi() {
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<i class="bi bi-lightning-charge me-1"></i>Import &amp; Generate Everything';
        progressBox.classList.add('d-none');
        progressBar.style.width = '0%';
    }

    function copyConfig() {
        const text = document.getElementById('jsonPreview').innerText.trim();
        navigator.clipboard.writeText(text).then(() => {
            const btn = document.getElementById('copyBtn');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied!';
            btn.classList.remove('btn-outline-light');
            btn.classList.add('btn-success');
            setTimeout(() => {
                btn.innerHTML = originalHtml;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-light');
            }, 2000);
        });
    }
</script>
</body>
</html>
