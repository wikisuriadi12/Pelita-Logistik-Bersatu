<?php
/** Unggah bukti pengiriman (POD): foto JPG/PNG atau PDF, maksimal sesuai config. */

function save_pod_files(int $orderId, string $field = 'pod'): array
{
    $errors = [];
    $saved = 0;
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
        return [0, []];
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    $max = (int)cfg('max_upload_mb', 5) * 1024 * 1024;
    $dir = rtrim((string)cfg('upload_dir', __DIR__ . '/../storage/pod'), '/\\');
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $f = $_FILES[$field];
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($f['name'] as $i => $origName) {
        $err = $f['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $label = mb_substr((string)$origName, 0, 60);
        if ($err !== UPLOAD_ERR_OK) {
            $errors[] = $label . ': unggahan gagal (kode ' . $err . ').';
            continue;
        }
        if ($f['size'][$i] > $max) {
            $errors[] = $label . ': ukuran melebihi ' . cfg('max_upload_mb', 5) . ' MB.';
            continue;
        }
        $mime = (string)$finfo->file($f['tmp_name'][$i]);
        if (!isset($allowed[$mime])) {
            $errors[] = $label . ': hanya JPG, PNG, atau PDF.';
            continue;
        }
        $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($f['tmp_name'][$i], $dir . DIRECTORY_SEPARATOR . $name)) {
            $errors[] = $label . ': tidak bisa disimpan di server.';
            continue;
        }
        q('INSERT INTO pod_files (order_id, filename, original_name, mime, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?)',
            [$orderId, $name, $label, $mime, (int)$f['size'][$i], user()['id'] ?? null]);
        $saved++;
    }
    return [$saved, $errors];
}

function pod_path(string $filename): string
{
    return rtrim((string)cfg('upload_dir', __DIR__ . '/../storage/pod'), '/\\') . DIRECTORY_SEPARATOR . basename($filename);
}
