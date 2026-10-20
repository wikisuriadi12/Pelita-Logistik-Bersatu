<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('dispatcher', 'manajemen');
$canEdit = has_role('dispatcher');
$pageTitle = 'Armada';
$active = 'vehicles';
$errors = [];
$types = ['CDE', 'CDD', 'Fuso', 'Tronton', 'Wingbox', 'Trailer 40 ft'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        fail(403, 'Akun Anda hanya bisa melihat data armada.');
    }
    $do = post('do');
    $id = (int)post('id');
    if ($do === 'save') {
        $cur = $id ? q_row('SELECT * FROM vehicles WHERE id = ?', [$id]) : null;
        $plate = strtoupper(post('plate'));
        $type = post('type');
        $cap = (int)post_num('capacity_kg');
        $kir = post('kir_expiry') === '' ? null : post_date('kir_expiry');
        $status = post('status');
        if ($plate === '') $errors[] = 'Plat nomor wajib diisi.';
        if (!in_array($type, $types, true)) $errors[] = 'Pilih jenis armada.';
        if ($cap < 1) $errors[] = 'Kapasitas minimal 1 kg.';
        if (post('kir_expiry') !== '' && !$kir) $errors[] = 'Tanggal KIR tidak valid.';
        if ($cur && $cur['status'] === 'bertugas') $status = 'bertugas';
        elseif (!in_array($status, ['tersedia', 'servis'], true)) $status = 'tersedia';
        if (!$errors && q_val('SELECT id FROM vehicles WHERE plate = ? AND id <> ?', [$plate, $id])) $errors[] = 'Plat ' . $plate . ' sudah terdaftar.';
        if (!$errors) {
            if ($id) {
                q('UPDATE vehicles SET plate=?, type=?, capacity_kg=?, kir_expiry=?, status=?, notes=? WHERE id=?', [$plate, $type, $cap, $kir, $status, post('notes'), $id]);
                audit('update', 'vehicle', $id, $plate);
            } else {
                q('INSERT INTO vehicles (plate, type, capacity_kg, kir_expiry, status, notes) VALUES (?,?,?,?,?,?)', [$plate, $type, $cap, $kir, $status, post('notes')]);
                audit('create', 'vehicle', (int)db()->lastInsertId(), $plate);
            }
            flash('Armada disimpan.');
            redirect('vehicles.php');
        }
    } elseif ($do === 'delete' && $id) {
        $cur = q_row('SELECT * FROM vehicles WHERE id = ?', [$id]);
        if ($cur && $cur['status'] === 'bertugas') flash('Armada sedang bertugas.', 'bad');
        elseif ((int)q_val('SELECT COUNT(*) FROM orders WHERE vehicle_id = ?', [$id]) > 0) flash('Armada sudah punya riwayat order. Ubah statusnya menjadi Servis.', 'bad');
        else { q('DELETE FROM vehicles WHERE id = ?', [$id]); audit('delete', 'vehicle', $id); flash('Armada dihapus.'); }
        redirect('vehicles.php');
    }
}

$edit = get('edit') !== '' ? q_row('SELECT * FROM vehicles WHERE id = ?', [(int)get('edit')]) : null;
$showForm = $canEdit && ($edit || get('new') !== '' || $errors);
$f = $errors
    ? ['id' => (int)post('id'), 'plate' => post('plate'), 'type' => post('type'), 'capacity_kg' => post('capacity_kg'), 'kir_expiry' => post('kir_expiry'), 'status' => post('status'), 'notes' => post('notes')]
    : ($edit ?: ['id' => 0, 'plate' => '', 'type' => 'CDD', 'capacity_kg' => '', 'kir_expiry' => '', 'status' => 'tersedia', 'notes' => '']);
$busy = $f['id'] && ($edit['status'] ?? '') === 'bertugas';

$rows = q_all("SELECT v.*, (SELECT o.order_no FROM orders o WHERE o.vehicle_id = v.id AND o.status IN ('dijadwalkan','jalan') ORDER BY o.id DESC LIMIT 1) AS active_order,
  (SELECT o.id FROM orders o WHERE o.vehicle_id = v.id AND o.status IN ('dijadwalkan','jalan') ORDER BY o.id DESC LIMIT 1) AS active_id
  FROM vehicles v ORDER BY v.plate");

require __DIR__ . '/includes/header.php';
?>
<div class="bar"><h2>Armada</h2><?php if ($canEdit): ?><a class="btn primary" href="vehicles.php?new=1">Tambah armada</a><?php endif; ?></div>
<?php foreach ($errors as $er): ?><div class="flash f-bad"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($showForm): ?>
<form method="post" class="card">
  <h3><?= $f['id'] ? 'Ubah armada' : 'Armada baru' ?></h3>
  <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
  <div class="form-grid">
    <label>Plat nomor <input name="plate" required value="<?= e($f['plate']) ?>" style="text-transform:uppercase"></label>
    <label>Jenis <select name="type"><?php foreach ($types as $t): ?><option<?= $f['type'] === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <label>Kapasitas (kg) <input type="number" name="capacity_kg" min="1" required value="<?= e($f['capacity_kg']) ?>"></label>
    <label>KIR berlaku sampai <input type="date" name="kir_expiry" value="<?= e($f['kir_expiry']) ?>"></label>
    <label>Status
      <select name="status"<?= $busy ? ' disabled' : '' ?>>
        <?php if ($busy): ?><option>bertugas</option><?php else: ?>
        <option value="tersedia"<?= $f['status'] === 'tersedia' ? ' selected' : '' ?>>Tersedia</option>
        <option value="servis"<?= $f['status'] === 'servis' ? ' selected' : '' ?>>Servis</option><?php endif; ?>
      </select>
    </label>
    <label>Catatan <input name="notes" value="<?= e($f['notes']) ?>"></label>
  </div>
  <div class="actions"><a class="btn" href="vehicles.php">Batal</a><button class="btn primary">Simpan</button></div>
</form>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="empty"><h3>Belum ada armada</h3><p>Tambahkan truk dengan plat, jenis, dan kapasitas supaya bisa dipakai di order.</p></div>
<?php else: ?>
<div class="tw"><table>
  <thead><tr><th>Plat</th><th>Jenis</th><th class="r">Kapasitas</th><th>KIR s/d</th><th>Status</th><th>Order aktif</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $v): $soon = $v['kir_expiry'] && $v['kir_expiry'] <= date('Y-m-d', strtotime('+30 days')); ?>
    <tr>
      <td class="num"><?= e($v['plate']) ?></td>
      <td><?= e($v['type']) ?></td>
      <td class="r num"><?= e(angka($v['capacity_kg'])) ?> kg</td>
      <td class="num"<?= $soon ? ' style="color:var(--bad)"' : '' ?>><?= e(tgl($v['kir_expiry'])) ?></td>
      <td><?= chip(vehicle_status_map(), $v['status']) ?></td>
      <td><?= $v['active_order'] ? '<a class="num" href="order_view.php?id=' . (int)$v['active_id'] . '">' . e($v['active_order']) . '</a>' : '<span class="muted">-</span>' ?></td>
      <td class="r"><?php if ($canEdit): ?><div class="row" style="justify-content:flex-end">
        <a class="btn sm" href="vehicles.php?edit=<?= (int)$v['id'] ?>">Ubah</a>
        <form method="post" data-confirm="Hapus armada ini?"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="btn sm danger">Hapus</button></form>
      </div><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
