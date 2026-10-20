<?php
/**
 * Inti aplikasi: konfigurasi, database, sesi, login, peran, dan fungsi bantu.
 * Dibuat atas nama Hendra Gunawan dan Wiki Suriadi.
 */

$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('config.php belum ada. Salin config.example.php menjadi config.php, isi koneksi database, lalu buka install.php.');
}
$GLOBALS['config'] = require $configFile;
date_default_timezone_set(cfg('timezone', 'Asia/Jakarta'));

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_name('pelita_tms');
session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/* ---------- konfigurasi dan database ---------- */

function cfg(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . cfg('db_timezone', '+07:00') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r ?: null;
}

function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Nomor berurutan yang aman dari tabrakan, mis. PLB-2610-0001. */
function next_number(string $name, string $prefix, int $pad = 4): string
{
    $pdo = db();
    $pdo->prepare('INSERT IGNORE INTO sequences (name, value) VALUES (?, 0)')->execute([$name]);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    $st = $pdo->prepare('SELECT value FROM sequences WHERE name = ? FOR UPDATE');
    $st->execute([$name]);
    $n = (int)$st->fetchColumn() + 1;
    $pdo->prepare('UPDATE sequences SET value = ? WHERE name = ?')->execute([$n, $name]);
    if ($own) {
        $pdo->commit();
    }
    return $prefix . str_pad((string)$n, $pad, '0', STR_PAD_LEFT);
}

function audit(string $action, string $entity, ?int $entityId = null, string $detail = ''): void
{
    $u = user();
    q('INSERT INTO audit_log (user_id, action, entity, entity_id, detail) VALUES (?,?,?,?,?)',
        [$u['id'] ?? null, $action, $entity, $entityId, mb_substr($detail, 0, 250)]);
}

/* ---------- tampilan dan format ---------- */

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rp($n): string
{
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

function angka($n): string
{
    return number_format((float)$n, 0, ',', '.');
}

function bulan_pendek(): array
{
    return ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
}

function tgl(?string $d): string
{
    if (!$d) {
        return '-';
    }
    $t = strtotime($d);
    if (!$t) {
        return '-';
    }
    return date('j', $t) . ' ' . bulan_pendek()[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}

function bulan_label(string $ym): string
{
    $t = strtotime($ym . '-01');
    return $t ? bulan_pendek()[(int)date('n', $t) - 1] . ' ' . date('Y', $t) : $ym;
}

function order_status_map(): array
{
    return [
        'baru'        => ['Baru', 'c-neutral'],
        'dijadwalkan' => ['Dijadwalkan', 'c-info'],
        'jalan'       => ['Dalam perjalanan', 'c-lamp'],
        'terkirim'    => ['Terkirim', 'c-ok'],
        'batal'       => ['Dibatalkan', 'c-bad'],
    ];
}

function vehicle_status_map(): array
{
    return ['tersedia' => ['Tersedia', 'c-ok'], 'bertugas' => ['Bertugas', 'c-lamp'], 'servis' => ['Servis', 'c-bad']];
}

function driver_status_map(): array
{
    return ['siaga' => ['Siaga', 'c-ok'], 'bertugas' => ['Bertugas', 'c-lamp'], 'cuti' => ['Cuti', 'c-neutral']];
}

function cost_category_map(): array
{
    return ['bbm' => 'BBM', 'tol' => 'Tol', 'uang_jalan' => 'Uang jalan', 'lain' => 'Lain-lain'];
}

function role_map(): array
{
    return [
        'admin'      => 'Admin',
        'dispatcher' => 'Dispatcher',
        'finance'    => 'Keuangan',
        'manajemen'  => 'Manajemen',
        'driver'     => 'Driver',
    ];
}

function chip(array $map, string $key): string
{
    $m = $map[$key] ?? [$key, 'c-neutral'];
    return '<span class="chip ' . e($m[1]) . '">' . e($m[0]) . '</span>';
}

function invoice_state(array $inv): array
{
    if ($inv['status'] === 'batal') {
        return ['Dibatalkan', 'c-bad'];
    }
    if ($inv['status'] === 'lunas') {
        return ['Lunas', 'c-ok'];
    }
    $paid = (float)($inv['paid'] ?? 0);
    if ($inv['due_date'] < date('Y-m-d')) {
        return ['Jatuh tempo', 'c-bad'];
    }
    return $paid > 0 ? ['Dibayar sebagian', 'c-info'] : ['Belum dibayar', 'c-lamp'];
}

/* ---------- input ---------- */

function post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function get(string $key, string $default = ''): string
{
    return trim((string)($_GET[$key] ?? $default));
}

function post_date(string $key): ?string
{
    $v = post($key);
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    return ($dt && $dt->format('Y-m-d') === $v) ? $v : null;
}

function post_num(string $key): float
{
    return max(0.0, (float)str_replace(',', '.', post($key, '0')));
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): array
{
    if ($msg !== null) {
        $_SESSION['flash'][] = [$type, $msg];
        return [];
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        exit('Sesi tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

/* ---------- login dan peran ---------- */

function user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function home_for(array $u): string
{
    return $u['role'] === 'driver' ? 'orders.php' : 'index.php';
}

function require_login(): array
{
    $u = user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

/** Admin selalu lolos. Tanpa argumen berarti khusus admin. */
function has_role(string ...$roles): bool
{
    $u = user();
    return $u && ($u['role'] === 'admin' || in_array($u['role'], $roles, true));
}

function require_role(string ...$roles): array
{
    $u = require_login();
    if (!has_role(...$roles)) {
        fail(403, 'Akun Anda tidak punya akses ke halaman ini.');
    }
    return $u;
}

function fail(int $code, string $message): void
{
    http_response_code($code);
    $pageTitle = $code === 404 ? 'Tidak ditemukan' : 'Akses ditolak';
    $active = '';
    require __DIR__ . '/header.php';
    echo '<div class="empty"><h2>' . e($pageTitle) . '</h2><p>' . e($message) . '</p></div>';
    require __DIR__ . '/footer.php';
    exit;
}

/* ---------- keluaran CSV (dibuka di Excel) ---------- */

function csv_out(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
    $clean = function ($v) {
        if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
            return "'" . $v; // cegah formula injection
        }
        return $v;
    };
    fputcsv($out, $header, ';');
    foreach ($rows as $r) {
        fputcsv($out, array_map($clean, $r), ';');
    }
    fclose($out);
    exit;
}
