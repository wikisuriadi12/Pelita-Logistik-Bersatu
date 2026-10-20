<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('dispatcher', 'finance', 'manajemen');
$canEdit = has_role('dispatcher', 'finance');
$pageTitle = 'Vendor';
$active = 'vendors';
$errors = [];
$types = ['Subkontraktor trucking', 'Bengkel dan suku cadang', 'SPBU / BBM', 'Tol dan parkir', 'Asuransi', 'Gudang dan bongkar muat', 'Lainnya'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        fail(403, 'Akun Anda hanya bisa melihat data vendor.');
    }
    $do = post('do');
    $id = (int)post('id');
    if ($do === 'save') {
        $f = [
            'name' => post('name'), 'type' => post('type'), 'contact_person' => post('contact_person'),
            'phone' => post('phone'), 'email' => post('email'), 'address' => post('address'), 'npwp' => post('npwp'),
            'bank_name' => post('bank_name'), 'bank_account' => post('bank_account'), 'bank_holder' => post('bank_holder'),
            'payment_terms_days' => (int)post_num('payment_terms_days'), 'notes' => post('notes'),
            'active' => isset($_POST['active']) ? 1 : 0,
        ];
        if ($f['name'] === '') $errors[] = 'Nama vendor wajib diisi.';
        if (!in_array($f['type'], $types, true)) $errors[] = 'Pilih jenis vendor.';
        if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';
        if ($f['bank_account'] !== '' && !preg_match('/^[0-9 .\-]{4,40}$/', $f['bank_account'])) $errors[] = 'Nomor rekening hanya boleh angka, spasi, titik, atau strip.';
        if ($f['payment_terms_days'] > 365) $errors[] = 'Tempo pembayaran maksimal 365 hari.';
        if (!$errors && q_val('SELECT id FROM vendors WHERE LOWER(name) = LOWER(?) AND id <> ?', [$f['name'], $id])) $errors[] = 'Vendor dengan nama ini sudah ada.';

        if (!$errors) {
            $vals = array_values($f);
            if ($id) {
                q('UPDATE vendors SET name=?, type=?, contact_person=?, phone=?, email=?, address=?, npwp=?, bank_name=?, bank_account=?, bank_holder=?, payment_terms_days=?, notes=?, active=? WHERE id=?', array_merge($vals, [$id]));
                audit('update', 'vendor', $id, $f['name']);
            } else {
                $code = next_number('vendor', 'VND-', 4);
                q('INSERT INTO vendors (name, type, contact_person, phone, email, address, npwp, bank_name, bank_account, bank_holder, payment_terms_days, notes, active, code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [$code]));
                audit('create', 'vendor', (int)db()->lastInsertId(), $code . ' ' . $f['name']);
            }
            flash('Vendor disimpan.');
            redirect('vendors.php');
        }
    } elseif ($do === 'toggle' && $id) {
        q('UPDATE vendors SET active = 1 - active WHERE id = ?', [$id]);
        audit('toggle', 'vendor', $id);
        flash('Status vendor diubah.');
        redirect('vendors.php');
    } elseif ($do === 'delete' && $id) {
        if ((int)q_val('SELECT COUNT(*) FROM order_costs WHERE vendor_id = ?', [$id]) > 0) {
            flash('Vendor sudah punya catatan biaya. Nonaktifkan saja.', 'bad');
        } else {
            q('DELETE FROM vendors WHERE id = ?', [$id]);
            audit('delete', 'vendor', $id);
            flash('Vendor dihapus.');
        }
        redirect('vendors.php');
    }
}

$edit = get('edit') !== '' ? q_row('SELECT * FROM vendors WHERE id = ?', [(int)get('edit')]) : null;
$showForm = $canEdit && ($edit || get('new') !== '' || $errors);
$blank = ['id' => 0, 'code' => '', 'name' => '', 'type' => $types[0], 'contact_person' => '', 'phone' => '', 'email' => '', 'address' => '', 'npwp' => '',
    'bank_name' => '', 'bank_account' => '', 'bank_holder' => '', 'payment_terms_days' => 0, 'notes' => '', 'active' => 1];
if ($errors) {
    $f = ['id' => (int)post('id'), 'code' => ''] + array_map(function ($v) { return $v; }, [
        'name' => post('name'), 'type' => post('type'), 'contact_person' => post('contact_person'), 'phone' => post('phone'), 'email' => post('email'),
        'address' => post('address'), 'npwp' => post('npwp'), 'bank_name' => post('bank_name'), 'bank_account' => post('bank_account'),
        'bank_holder' => post('bank_holder'), 'payment_terms_days' => post('payment_terms_days'), 'notes' => post('notes'), 'active' => isset($_POST['active']) ? 1 : 0,
    ]);
} else {
    $f = $edit ?: $blank;
}

$type = get('type');
$qs = get('q');
$where = [];
$p = [];
if (in_array($type, $types, true)) { $where[] = 'v.type = ?'; $p[] = $type; }
if ($qs !== '') {
    $where[] = '(v.name LIKE ? OR v.code LIKE ? OR v.contact_person LIKE ?)';
    $like = '%' . $qs . '%';
    array_push($p, $like, $like, $like);
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$rows = q_all("SELECT v.*, COALESCE((SELECT SUM(x.amount) FROM order_costs x WHERE x.vendor_id = v.id),0) AS spent,
    (SELECT COUNT(*) FROM order_costs x WHERE x.vendor_id = v.id) AS uses
  FROM vendors v $w ORDER BY v.active DESC, v.name", $p);

require __DIR__ . '/includes/header.php';
?>
<div class="bar"><h2>Master vendor</h2><?php if ($canEdit): ?><a class="btn primary" href="vendors.php?new=1">Tambah vendor</a><?php endif; ?></div>
<?php foreach ($errors as $er): ?><div class="flash f-bad"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($showForm): ?>
<form method="post" class="card">
  <h3><?= $f['id'] ? 'Ubah vendor ' . e($f['code']) : 'Vendor baru' ?></h3>
  <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
  <div class="form-grid">
    <label>Nama vendor <input name="name" required value="<?= e($f['name']) ?>"></label>
    <label>Jenis
      <select name="type"><?php foreach ($types as $t): ?><option<?= $f['type'] === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    </label>
    <label>Nama kontak <input name="contact_person" value="<?= e($f['contact_person']) ?>"></label>
    <label>Telepon <input type="tel" name="phone" value="<?= e($f['phone']) ?>"></label>
    <label>Email <input type="email" name="email" value="<?= e($f['email']) ?>"></label>
    <label>NPWP <input name="npwp" value="<?= e($f['npwp']) ?>"></label>
    <label style="grid-column:1/-1">Alamat <input name="address" value="<?= e($f['address']) ?>"></label>
    <label>Bank <input name="bank_name" value="<?= e($f['bank_name']) ?>" placeholder="BCA, Mandiri, BRI"></label>
    <label>Nomor rekening <input name="bank_account" inputmode="numeric" value="<?= e($f['bank_account']) ?>"></label>
    <label>Atas nama <input name="bank_holder" value="<?= e($f['bank_holder']) ?>"></label>
    <label>Tempo pembayaran (hari) <input type="number" name="payment_terms_days" min="0" max="365" step="1" value="<?= e($f['payment_terms_days']) ?>"></label>
    <label style="grid-column:1/-1">Catatan <input name="notes" maxlength="255" value="<?= e($f['notes']) ?>"></label>
    <label class="inline"><input type="checkbox" name="active" value="1"<?= $f['active'] ? ' checked' : '' ?>> Vendor aktif</label>
  </div>
  <div class="actions"><a class="btn" href="vendors.php">Batal</a><button class="btn primary">Simpan</button></div>
</form>
<?php endif; ?>

<form method="get" class="filters">
  <input type="search" name="q" value="<?= e($qs) ?>" placeholder="Cari nama, kode, kontak" aria-label="Cari vendor">
  <select name="type" aria-label="Jenis vendor">
    <option value="">Semua jenis</option>
    <?php foreach ($types as $t): ?><option<?= $type === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
  </select>
  <button class="btn">Terapkan</button>
</form>

<?php if (!$rows): ?>
  <div class="empty">
    <h3><?= $where ? 'Tidak ada vendor yang cocok' : 'Belum ada vendor' ?></h3>
    <p><?= $where ? 'Ubah kata kunci atau jenis vendor.' : 'Catat subkontraktor, bengkel, SPBU, dan pemasok lain beserta rekeningnya, lalu pilih saat mencatat biaya order.' ?></p>
  </div>
<?php else: ?>
<div class="tw"><table>
  <thead><tr><th>Kode</th><th>Vendor</th><th>Jenis</th><th>Kontak</th><th>Rekening</th><th class="r">Tempo</th><th class="r">Biaya tercatat</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $v): ?>
    <tr>
      <td class="num"><?= e($v['code']) ?></td>
      <td><b><?= e($v['name']) ?></b><?= $v['npwp'] ? '<br><span class="muted">NPWP ' . e($v['npwp']) . '</span>' : '' ?></td>
      <td><?= e($v['type']) ?></td>
      <td><?= e($v['contact_person'] ?: '-') ?><br><span class="muted num"><?= e($v['phone']) ?></span><?= $v['email'] ? '<br><span class="muted">' . e($v['email']) . '</span>' : '' ?></td>
      <td><?= $v['bank_account'] ? e($v['bank_name']) . '<br><span class="num">' . e($v['bank_account']) . '</span><br><span class="muted">' . e($v['bank_holder']) . '</span>' : '<span class="muted">-</span>' ?></td>
      <td class="r num"><?= (int)$v['payment_terms_days'] ? (int)$v['payment_terms_days'] . ' hari' : 'Tunai' ?></td>
      <td class="r num"><?= e(rp($v['spent'])) ?><br><span class="muted"><?= (int)$v['uses'] ?> catatan</span></td>
      <td><?= $v['active'] ? '<span class="chip c-ok">Aktif</span>' : '<span class="chip">Nonaktif</span>' ?></td>
      <td class="r"><?php if ($canEdit): ?><div class="row" style="justify-content:flex-end">
        <a class="btn sm" href="vendors.php?edit=<?= (int)$v['id'] ?>">Ubah</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="btn sm"><?= $v['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
        <?php if (!(int)$v['uses']): ?><form method="post" data-confirm="Hapus vendor ini?"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="btn sm danger">Hapus</button></form><?php endif; ?>
      </div><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
