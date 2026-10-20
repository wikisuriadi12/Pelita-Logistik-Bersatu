<?php
require __DIR__ . '/includes/bootstrap.php';

if (user()) {
    redirect(home_for(user()));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = strtolower(post('username'));
    $password = (string)($_POST['password'] ?? '');
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    q('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    $fails = (int)q_val('SELECT COUNT(*) FROM login_attempts WHERE (ip = ? OR username = ?) AND attempted_at > (NOW() - INTERVAL 15 MINUTE)', [$ip, $username]);

    if ($fails >= 5) {
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.';
    } else {
        $row = q_row('SELECT * FROM users WHERE username = ? AND active = 1', [$username]);
        if ($row && password_verify($password, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$row['id'], 'username' => $row['username'], 'name' => $row['name'],
                'role' => $row['role'], 'driver_id' => $row['driver_id'] !== null ? (int)$row['driver_id'] : null,
            ];
            audit('login', 'user', (int)$row['id']);
            redirect(home_for($_SESSION['user']));
        }
        q('INSERT INTO login_attempts (ip, username) VALUES (?, ?)', [$ip, mb_substr($username, 0, 40)]);
        $error = 'Nama pengguna atau kata sandi salah.';
    }
}

$pageTitle = 'Masuk';
$active = '';
require __DIR__ . '/includes/header.php';
?>
<div class="card login">
  <h2>Masuk</h2>
  <?php if ($error): ?><div class="flash f-bad"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form-grid" style="grid-template-columns:1fr">
    <?= csrf_field() ?>
    <label>Nama pengguna <input name="username" autocomplete="username" required autofocus></label>
    <label>Kata sandi <input type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn primary">Masuk</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
