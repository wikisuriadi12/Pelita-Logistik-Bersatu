<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('dispatcher', 'manajemen');
$canEdit = has_role('dispatcher');
$pageTitle = 'Driver';
$active = 'drivers';
$errors = [];
$sims = ['B1 Umum', 'B2 Umum', 'B1', 'B2'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        fail(403, 'Akun Anda hanya bisa melihat data driver.');
    }
    $do = post('do');
    $id = (int)post('id');
    if ($do === 'save') {
        $cur = $id ? q_row('SELECT * FROM drivers WHERE id = ?', [$id]) : null;
        $name = post('name');
        $sim = post('sim_type');
        $simExp = post('sim_expiry') === '' ? null : post_date('sim_expiry');
        $status = post('status');
        if ($name === '') $errors[] = 'Nama driver wajib diisi.';
        if (!in_array($sim, $sims, true)) $errors[] = 'Pilih jenis SIM.';
        if (post('sim_expiry') !== '' && !$simExp) $errors[] = 'Tanggal SIM tidak valid.';
        if ($cur && $cur['status'] === 'bertugas') $status = 'bertugas';
        elseif (!in_array($status, ['siaga', 'cuti'], true)) $status = 'siaga';
        if (!$errors) {
            if ($id) {
                q('UPDATE drivers SET name=?, phone=?, sim_type=?, sim_expiry=?, status=? WHERE id=?', [$name, post('phone'), $sim, $simExp, $status, $id]);
                audit('update', 'driver', $id, $name);
            } else {
                q('INSERT INTO drivers (name, phone, sim_type, sim_expiry, status) VALUES (?,?,?,?,?)', [$name, post('phone'), $sim, $simExp, $status]);
                audit('create', 'driver', (int)db()->lastInsertId(), $name);
            }
            flash('Driver disimpan.');
            redirect('drivers.php');
        }
    } elseif ($do === 'delete' && $id) {
        $cur = q_row('SELECT * FROM drivers WHERE id = ?', [$id]);
        if ($cur && $cur['status'] === 'bertugas') flash('Driver sedang bertugas.', 'bad');
        elseif ((int)q_val('SELECT COUNT(*) FROM orders WHERE driver_id = ?', [$id]) > 0) flash('Driver sudah punya riwayat order. Ubah statusnya menjadi Cuti.', 'bad');
        elseif ((int)q_val('SELECT COUNT(*) FROM users WHERE driver_id = ?', [$id]) > 0) flash('Driver masih terhubung ke akun pengguna.', 'bad');
        else { q('DELETE FROM drivers WHERE id = ?', [$id]); audit('delete', 'driver', $id); flash('Driver dihapus.'); }
        redirect('drivers.php');
    }
}

$edit = get('edit') !== '' ? q_row('SELECT * FROM drivers WHERE id = ?', [(int)get('edit')]) : null;
$showForm = $canEdit && ($edit || get('new') !== '' || $errors);
$f = $errors
    ? ['id' => (int)post('id'), 'name' => post('name'), 'phone' => post('phone'), 'sim_type' => post('sim_type'), 'sim_expiry' => post('sim_expiry'), 'status' => post('status')]
    : ($edit ?: ['id' => 0, 'name' => '', 'phone' => '', 'sim_type' => 'B2 Umum', 'sim_expiry' => '', 'status' => 'siaga']);
$busy = $f['id'] && ($edit['status'] ?? '') === 'bertugas';

$rows = q_all("SELECT d.*, (SELECT o.order_no FROM orders o WHERE o.driver_id = d.id AND o.status IN ('dijadwalkan','jalan') ORDER BY o.id DESC LIMIT 1) AS active_order,
  (SELECT o.id FROM orders o WHERE o.driver_id = d.id AND o.status IN ('dijadwalkan','jalan') ORDER BY o.id DESC LIMIT 1) AS active_id
  FROM drivers d ORDER BY d.name");

require __DIR__ . '/includes/header.php';
?>
<div class="bar"><h2>Driver</h2><?php if ($canEdit): ?><a class="btn primary" href="drivers.php?new=1">Tambah driver</a><?php endif; ?></div>
<?php foreach ($errors as $er): ?><div class="flash f-bad"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($showForm): ?>
<form method="post" class="card">
  <h3><?= $f['id'] ? 'Ubah driver' : 'Driver baru' ?></h3>
  <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
  <div class="form-grid">
    <label>Nama lengkap <input name="name" required value="<?= e($f['name']) ?>"></label>
    <label>Telepon <input type="tel" name="phone" value="<?= e($f['phone']) ?>"></label>
    <label>Jenis SIM <select name="sim_type"><?php foreach ($sims as $s): ?><option<?= $f['sim_type'] === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
    <label>SIM berlaku sampai <input type="date" name="sim_expiry" value="<?= e($f['sim_expiry']) ?>"></label>
    <label>Status
      <select name="status"<?= $busy ? ' disabled' : '' ?>>
        <?php if ($busy): ?><option>bertugas</option><?php else: ?>
        <option value="siaga"<?= $f['status'] === 'siaga' ? ' selected' : '' ?>>Siaga</option>
        <option value="cuti"<?= $f['status'] === 'cuti' ? ' selected' : '' ?>>Cuti</option><?php endif; ?>
      </select>
    </label>
  </div>
  <div class="actions"><a class="btn" href="drivers.php">Batal</a><button class="btn primary">Simpan</button></div>
</form>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="empty"><h3>Belum ada driver</h3><p>Tambahkan driver beserta jenis SIM dan masa berlakunya.</p></div>
<?php else: ?>
<div class="tw"><table>
  <thead><tr><th>Nama</th><th>Telepon</th><th>SIM</th><th>SIM s/d</th><th>Status</th><th>Order aktif</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $d): $soon = $d['sim_expiry'] && $d['sim_expiry'] <= date('Y-m-d', strtotime('+30 days')); ?>
    <tr>
      <td><?= e($d['name']) ?></td>
      <td class="num"><?= e($d['phone'] ?: '-') ?></td>
      <td><?= e($d['sim_type'] ?: '-') ?></td>
      <td class="num"<?= $soon ? ' style="color:var(--bad)"' : '' ?>><?= e(tgl($d['sim_expiry'])) ?></td>
      <td><?= chip(driver_status_map(), $d['status']) ?></td>
      <td><?= $d['active_order'] ? '<a class="num" href="order_view.php?id=' . (int)$d['active_id'] . '">' . e($d['active_order']) . '</a>' : '<span class="muted">-</span>' ?></td>
      <td class="r"><?php if ($canEdit): ?><div class="row" style="justify-content:flex-end">
        <a class="btn sm" href="drivers.php?edit=<?= (int)$d['id'] ?>">Ubah</a>
        <form method="post" data-confirm="Hapus driver ini?"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn sm danger">Hapus</button></form>
      </div><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
