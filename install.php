<?php
/**
 * Pemasang sekali pakai: membuat tabel dan akun admin pertama.
 * HAPUS file ini dari server setelah selesai.
 */
require __DIR__ . '/includes/bootstrap.php';

$lock = __DIR__ . '/storage/installed.lock';
if (is_file($lock)) {
    http_response_code(403);
    exit('Sudah terpasang. Hapus install.php dari server.');
}

$errors = [];
$done = false;
$dbOk = true;
try {
    db();
} catch (Throwable $e) {
    $dbOk = false;
    $errors[] = 'Tidak bisa terhubung ke database. Periksa config.php (host, nama database, pengguna, kata sandi).';
}

if ($dbOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = post('name');
    $username = strtolower(post('username'));
    $password = (string)($_POST['password'] ?? '');

    if ($name === '') $errors[] = 'Nama lengkap wajib diisi.';
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) $errors[] = 'Nama pengguna 3-40 karakter: huruf kecil, angka, titik, strip.';
    if (strlen($password) < 8) $errors[] = 'Kata sandi minimal 8 karakter.';

    if (!$errors) {
        try {
            $sql = file_get_contents(__DIR__ . '/database/schema.sql');
            foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '') {
                    db()->exec($stmt);
                }
            }
            if ((int)q_val('SELECT COUNT(*) FROM users') > 0) {
                $errors[] = 'Database sudah berisi pengguna. Pemasangan dihentikan.';
            } else {
                q('INSERT INTO users (username, password_hash, name, role) VALUES (?,?,?,?)',
                    [$username, password_hash($password, PASSWORD_DEFAULT), $name, 'admin']);
                if (!is_dir(dirname($lock))) {
                    @mkdir(dirname($lock), 0750, true);
                }
                file_put_contents($lock, date('c'));
                $done = true;
            }
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $errors[] = 'Pemasangan gagal: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Pemasangan';
$active = '';
require __DIR__ . '/includes/header.php';
?>
<div class="card login" style="max-width:480px">
  <h2>Pemasangan sistem</h2>
  <?php foreach ($errors as $er): ?><div class="flash f-bad"><?= e($er) ?></div><?php endforeach; ?>
  <?php if ($done): ?>
    <div class="flash f-ok">Pemasangan selesai. Akun admin sudah dibuat.</div>
    <p><b>Penting:</b> hapus file <span class="num">install.php</span> dari server sekarang.</p>
    <a class="btn primary" href="login.php">Masuk</a>
  <?php elseif ($dbOk): ?>
    <p class="muted">Langkah ini membuat semua tabel dan satu akun admin.</p>
    <form method="post" class="form-grid" style="grid-template-columns:1fr">
      <?= csrf_field() ?>
      <label>Nama lengkap admin <input name="name" required value="<?= e(post('name')) ?>"></label>
      <label>Nama pengguna <input name="username" required value="<?= e(post('username')) ?>"></label>
      <label>Kata sandi (minimal 8 karakter) <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <button class="btn primary">Pasang</button>
    </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
