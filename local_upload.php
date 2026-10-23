<?php
require_once('rl_init.php');
ignore_user_abort(true);

if (!is__writable(DOWNLOAD_DIR)) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Download directory not writable']);
    exit;
}

if (!isset($_FILES['localfile'])) {
    http_response_code(400);
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxSize = trim((string)ini_get('post_max_size'));
    $postMaxBytes = 0;
    if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMG])?B?$/i', $postMaxSize, $matches)) {
        $postMaxBytes = (int)((float)$matches[1] * (['K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($matches[2] ?? '')] ?? 1));
    }
    $message = $contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes
        ? 'Upload exceeds the server request limit (post_max_size: ' . $postMaxSize . ').'
        : 'No file was received. Please choose a file and try again.';
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

// Check forbidden filetypes
if ($options['forbidden_filetypes_block']) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array('.' . $ext, $options['forbidden_filetypes'])) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'File type not allowed']);
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