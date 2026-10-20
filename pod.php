<?php
/** Menyajikan file bukti pengiriman hanya untuk pengguna yang berhak. */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/pod.php';
$u = require_login();

$pod = q_row('SELECT p.*, o.driver_id FROM pod_files p JOIN orders o ON o.id = p.order_id WHERE p.id = ?', [(int)get('id')]);
if (!$pod) {
    fail(404, 'File tidak ditemukan.');
}
$staff = has_role('dispatcher', 'finance', 'manajemen');
$own = $u['role'] === 'driver' && $pod['driver_id'] !== null && (int)$u['driver_id'] === (int)$pod['driver_id'];
if (!$staff && !$own) {
    fail(403, 'Anda tidak berhak melihat file ini.');
}
$path = pod_path($pod['filename']);
if (!is_file($path)) {
    fail(404, 'File sudah tidak ada di server.');
}
header('Content-Type: ' . $pod['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $pod['original_name']) . '"');
header('Cache-Control: private, max-age=3600');
readfile($path);
