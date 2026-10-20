<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role(); // khusus admin
$pageTitle = 'Pengguna';
$active = 'users';
$errors = [];
$roles = role_map();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)post('id');
    $username = strtolower(post('username'));
    $name = post('name');
    $role = post('role');
    $driverId = (int)post('driver_id') ?: null;
    $pass = (string)($_POST['password'] ?? '');
    $active_flag = isset($_POST['active']) ? 1 : 0;
    $self = $id === (int)$u['id'];

    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) $errors[] = 'Nama pengguna 3-40 karakter: huruf kecil, angka, titik, strip.';
    if ($name === '') $errors[] = 'Nama lengkap wajib diisi.';
    if (!isset($roles[$role])) $errors[] = 'Pilih peran.';
    if ($role === 'driver' && !$driverId) $errors[] = 'Akun driver harus dihubungkan ke data driver.';
    if ($role !== 'driver') $driverId = null;
    if (!$id && strlen($pass) < 8) $errors[] = 'Kata sandi minimal 8 karakter.';
    if ($id && $pass !== '' && strlen($pass) < 8) $errors[] = 'Kata sandi baru minimal 8 karakter.';
    if ($self && ($role !== 'admin' || !$active_flag)) $errors[] = 'Anda tidak bisa menurunkan atau menonaktifkan akun sendiri.';
    if (!$errors && q_val('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id])) $errors[] = 'Nama pengguna sudah dipakai.';

    if (!$errors) {
        if ($id) {
            q('UPDATE users SET username=?, name=?, role=?, driver_id=?, active=? WHERE id=?', [$username, $name, $role, $driverId, $active_flag, $id]);
            if ($pass !== '') q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            audit('update', 'user', $id, $username);
        } else {
            q('INSERT INTO users (username, password_hash, name, role, driver_id, active) VALUES (?,?,?,?,?,?)', [$username, password_hash($pass, PASSWORD_DEFAULT), $name, $role, $driverId, $active_flag]);
            audit('create', 'user', (int)db()->lastInsertId(), $username);
        }
        flash('Pengguna disimpan.');
        redirect('users.php');
    }
}

$edit = get('edit') !== '' ? q_row('SELECT * FROM users WHERE id = ?', [(int)get('edit')]) : null;
$showForm = $edit || get('new') !== '' || $errors;
$f = $errors
    ? ['id' => (int)post('id'), 'username' => post('username'), 'name' => post('name'), 'role' => post('role'), 'driver_id' => (int)post('driver_id'), 'active' => isset($_POST['active']) ? 1 : 0]
    : ($edit ?: ['id' => 0, 'username' => '', 'name' => '', 'role' => 'dispatcher', 'driver_id' => 0, 'active' => 1]);
$drivers = q_all('SELECT id, name FROM drivers ORDER BY name');
$rows = q_all('SELECT u.*, d.name AS driver FROM users u LEFT JOIN drivers d ON d.id = u.driver_id ORDER BY u.active DESC, u.name');

require __DIR__ . '/includes/header.php';
?>
<div class="bar"><h2>Pengguna</h2><a class="btn primary" href="users.php?new=1">Tambah pengguna</a></div>
<?php foreach ($errors as $er): ?><div class="flash f-bad"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($showForm): ?>
<form method="post" class="card">
  <h3><?= $f['id'] ? 'Ubah pengguna' : 'Pengguna baru' ?></h3>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
  <div class="form-grid">
    <label>Nama lengkap <input name="name" required value="<?= e($f['name']) ?>"></label>
    <label>Nama pengguna <input name="username" required value="<?= e($f['username']) ?>" autocomplete="off"></label>
    <label>Peran
      <select name="role"><?php foreach ($roles as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['role'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    </label>
    <label>Data driver (khusus peran Driver)
      <select name="driver_id"><option value="">-</option>
        <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)$f['driver_id'] === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><?= $f['id'] ? 'Kata sandi baru (kosongkan bila tidak diganti)' : 'Kata sandi (minimal 8 karakter)' ?> <input type="password" name="password" autocomplete="new-password"<?= $f['id'] ? '' : ' required minlength="8"' ?>></label>
    <label class="inline" style="align-self:end"><input type="checkbox" name="active" value="1"<?= $f['active'] ? ' checked' : '' ?>> Akun aktif</label>
  </div>
  <div class="actions"><a class="btn" href="users.php">Batal</a><button class="btn primary">Simpan</button></div>
</form>
<?php endif; ?>

<div class="tw"><table>
  <thead><tr><th>Nama</th><th>Pengguna</th><th>Peran</th><th>Driver</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['name']) ?></td>
      <td class="num"><?= e($r['username']) ?></td>
      <td><?= e($roles[$r['role']] ?? $r['role']) ?></td>
      <td><?= e($r['driver'] ?? '-') ?></td>
      <td><?= $r['active'] ? '<span class="chip c-ok">Aktif</span>' : '<span class="chip">Nonaktif</span>' ?></td>
      <td class="r"><a class="btn sm" href="users.php?edit=<?= (int)$r['id'] ?>">Ubah</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="muted">Peran: Admin (semua akses), Dispatcher (order, armada, driver, pelanggan), Keuangan (invoice, pembayaran, biaya, laporan), Manajemen (lihat ringkasan, order, invoice, laporan), Driver (hanya order miliknya).</p>
<?php require __DIR__ . '/includes/footer.php'; ?>
