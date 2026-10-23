<?php
require_once('rl_init.php');
ignore_user_abort(true);

// Detect post_max_size overflow BEFORE anything that depends on $_POST/$_FILES:
// PHP drops the whole request body when it exceeds post_max_size, so CSRF
// validation would fail with an HTML error that breaks the JSON contract.
$postMaxSize = trim((string)ini_get('post_max_size'));
$postMaxBytes = 0;
if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMG])?B?$/i', $postMaxSize, $matches)) {
    $postMaxBytes = (int)((float)$matches[1] * (['K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($matches[2] ?? '')] ?? 1));
}
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    http_response_code(413);
    echo json_encode(['status'=>'error','message'=>'Upload exceeds the server request limit (post_max_size: ' . $postMaxSize . '). Ask the administrator to raise it in the admin panel.']);
    exit;
}

// CSRF check with a JSON response instead of rl_csrf_validate()'s HTML error.
$sentToken = isset($_POST['rl_csrf']) && is_string($_POST['rl_csrf']) ? $_POST['rl_csrf'] : (isset($_SERVER['HTTP_X_RL_CSRF']) && is_string($_SERVER['HTTP_X_RL_CSRF']) ? $_SERVER['HTTP_X_RL_CSRF'] : '');
if ($sentToken === '' || rl_csrf_token() === '' || !hash_equals(rl_csrf_token(), $sentToken)) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Session expired. Reload the page and try again.']);
    exit;
}

if (!is__writable(DOWNLOAD_DIR)) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Download directory not writable']);
    exit;
}

if (!isset($_FILES['localfile'])) {
    http_response_code(400);
    $message = 'No file was received. Please choose a file and try again.';
    echo json_encode(['status'=>'error','message'=>$message]);
    exit;
}

$upload = $_FILES['localfile'];
if ($upload['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds the server upload limit (upload_max_filesize: ' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds the upload form size limit.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please retry.',
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.',
    ];
    echo json_encode(['status'=>'error','message'=>$uploadErrors[$upload['error']] ?? 'The upload failed with error code ' . $upload['error'] . '.']);
    exit;
}

$filename = basename($upload['name']);
$target = DOWNLOAD_DIR . $filename;

// file_size_limit is configured in MiB.
if ($options['file_size_limit'] > 0 && $upload['size'] > ($options['file_size_limit'] * 1024 * 1024)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'File exceeds size limit']);
    exit;
}

// Local upload file-type policy: by default accept all types (stored files are
// inert data — files/.htaccess blocks script execution). Admins can restrict to
// a whitelist via the admin panel.
$allowAll = !isset($options['local_upload_allow_all']) || !empty($options['local_upload_allow_all']);
if (!$allowAll) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowed = array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($options['local_upload_allowed_types'] ?? ''))));
    $allowed = array_map(fn($t) => strtolower(ltrim($t, '.')), $allowed);
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'File type not allowed by server policy']);
        exit;
    }
}

// Ensure unique filename
$counter = 1;
while (file_exists($target)) {
    $parts = pathinfo($filename);
    $filename = $parts['filename'] . '_' . $counter . '.' . $parts['extension'];
    $target = DOWNLOAD_DIR . $filename;
    $counter++;
}

if (move_uploaded_file($upload['tmp_name'], $target)) {
    // Update files.lst with the same format as index.php
    $fileInfo = array(
        'name' => $target,
        'size' => filesize($target),
        'date' => time(),
        'link' => '',
        'comment' => '',
        'owner' => (defined('USER_TOKEN') ? USER_TOKEN : '')
    );
    // Append to files.lst using write_file for consistency
    if (!write_file(CONFIG_DIR . 'files.lst', serialize($fileInfo) . "\r\n", 0)) {
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Failed to update file list']);
        exit;
    }
    $filePath = $target;
    $fileUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/' . basename($target);
    echo json_encode(['status'=>'ok','filename'=>$filename,'link'=>$fileUrl,'path'=>$filePath]);
} else {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Failed to move file']);
}
?>