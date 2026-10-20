<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('dispatcher', 'finance');
$pageTitle = 'Pelanggan';
$active = 'customers';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = post('do');
    $id = (int)post('id');
    if ($do === 'save') {
        $f = ['name' => post('name'), 'address' => post('address'), 'phone' => post('phone'), 'email' => post('email'), 'npwp' => post('npwp')];
        if ($f['name'] === '') $errors[] = 'Nama pelanggan wajib diisi.';
        if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';
        if (!$errors) {
            if ($id) {
                q('UPDATE customers SET name=?, address=?, phone=?, email=?, npwp=? WHERE id=?', [$f['name'], $f['address'], $f['phone'], $f['email'], $f['npwp'], $id]);
                audit('update', 'customer', $id, $f['name']);
            } else {
                q('INSERT INTO customers (name, address, phone, email, npwp) VALUES (?,?,?,?,?)', [$f['name'], $f['address'], $f['phone'], $f['email'], $f['npwp']]);
                audit('create', 'customer', (int)db()->lastInsertId(), $f['name']);
            }
            flash('Pelanggan disimpan.');
            redirect('customers.php');
        }
    } elseif ($do === 'toggle' && $id) {
        q('UPDATE customers SET active = 1 - active WHERE id = ?', [$id]);
        audit('toggle', 'customer', $id);
        flash('Status pelanggan diubah.');
        redirect('customers.php');
    }
}

$edit = null;
if (get('edit') !== '') {
    $edit = q_row('SELECT * FROM customers WHERE id = ?', [(int)get('edit')]);
}
$showForm = $edit || get('new') !== '' || $errors;
$f = $errors ? ['id' => (int)post('id'), 'name' => post('name'), 'address' => post('address'), 'phone' => post('phone'), 'email' => post('email'), 'npwp' => post('npwp')]
    : ($edit ?: ['id' => 0, 'name' => '', 'address' => '', 'phone' => '', 'email' => '', 'npwp' => '']);

$rows = q_all('SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS orders FROM customers c ORDER BY c.active DESC, c.name');

require __DIR__ . '/includes/header.php';
?>
<div class="bar"><h2>Pelanggan</h2><a class="btn primary" href="customers.php?new=1">Tambah pelanggan</a></div>
<?php foreach ($errors as $er): ?><div class="flash f-bad"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($showForm): ?>
<form method="post" class="card">
  <h3><?= $f['id'] ? 'Ubah pelanggan' : 'Pelanggan baru' ?></h3>
  <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
  <div class="form-grid">
    <label>Nama <input name="name" required value="<?= e($f['name']) ?>"></label>
    <label>Telepon <input name="phone" value="<?= e($f['phone']) ?>"></label>
    <label>Email <input type="email" name="email" value="<?= e($f['email']) ?>"></label>
    <label>NPWP <input name="npwp" value="<?= e($f['npwp']) ?>"></label>
    <label style="grid-column:1/-1">Alamat <input name="address" value="<?= e($f['address']) ?>"></label>
  </div>
  <div class="actions"><a class="btn" href="customers.php">Batal</a><button class="btn primary">Simpan</button></div>
</form>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="empty"><h3>Belum ada pelanggan</h3><p>Tambahkan pelanggan supaya bisa dipakai di order dan invoice.</p></div>
<?php else: ?>
<div class="tw"><table>
  <thead><tr><th>Nama</th><th>Kontak</th><th>NPWP</th><th class="r">Order</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr>
      <td><?= e($c['name']) ?><br><span class="muted"><?= e($c['address']) ?></span></td>
      <td><?= e($c['phone']) ?><br><span class="muted"><?= e($c['email']) ?></span></td>
      <td class="num"><?= e($c['npwp'] ?: '-') ?></td>
      <td class="r num"><?= (int)$c['orders'] ?></td>
      <td><?= $c['active'] ? '<span class="chip c-ok">Aktif</span>' : '<span class="chip">Nonaktif</span>' ?></td>
      <td class="r"><div class="row" style="justify-content:flex-end">
        <a class="btn sm" href="customers.php?edit=<?= (int)$c['id'] ?>">Ubah</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn sm"><?= $c['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
