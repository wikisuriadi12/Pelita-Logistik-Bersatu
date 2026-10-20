<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('dispatcher');
$active = 'orders';

$id = (int)get('id');
$order = $id ? q_row('SELECT * FROM orders WHERE id = ?', [$id]) : null;
if ($id && !$order) {
    fail(404, 'Order tidak ditemukan.');
}
if ($order && $order['status'] !== 'baru') {
    flash('Order yang sudah dijadwalkan tidak bisa diubah. Batalkan lalu buat order baru bila perlu.', 'bad');
    redirect('order_view.php?id=' . $id);
}

$customers = q_all('SELECT id, name FROM customers WHERE active = 1 ORDER BY name');
$f = $order ?: [
    'customer_id' => '', 'origin' => '', 'destination' => '', 'cargo' => '', 'weight_kg' => '',
    'pickup_date' => date('Y-m-d'), 'eta_date' => date('Y-m-d', strtotime('+2 days')), 'freight' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = [
        'customer_id' => (int)post('customer_id'),
        'origin'      => post('origin'),
        'destination' => post('destination'),
        'cargo'       => post('cargo'),
        'weight_kg'   => (int)post_num('weight_kg'),
        'pickup_date' => post_date('pickup_date'),
        'eta_date'    => post_date('eta_date'),
        'freight'     => post_num('freight'),
    ];
    if (!q_val('SELECT id FROM customers WHERE id = ? AND active = 1', [$f['customer_id']])) $errors[] = 'Pilih pelanggan.';
    if ($f['origin'] === '' || $f['destination'] === '') $errors[] = 'Kota asal dan tujuan wajib diisi.';
    if ($f['cargo'] === '') $errors[] = 'Isi jenis muatan.';
    if ($f['weight_kg'] < 1) $errors[] = 'Berat muatan minimal 1 kg.';
    if (!$f['pickup_date'] || !$f['eta_date']) $errors[] = 'Tanggal ambil dan estimasi tiba wajib diisi.';
    elseif ($f['eta_date'] < $f['pickup_date']) $errors[] = 'Estimasi tiba tidak boleh sebelum tanggal ambil.';

    if (!$errors) {
        if ($order) {
            q('UPDATE orders SET customer_id=?, origin=?, destination=?, cargo=?, weight_kg=?, pickup_date=?, eta_date=?, freight=? WHERE id=?',
                [$f['customer_id'], $f['origin'], $f['destination'], $f['cargo'], $f['weight_kg'], $f['pickup_date'], $f['eta_date'], $f['freight'], $id]);
            audit('update', 'order', $id);
            flash('Order diperbarui.');
            redirect('order_view.php?id=' . $id);
        }
        $no = next_number('ord-' . date('ym'), 'PLB-' . date('ym') . '-');
        q('INSERT INTO orders (order_no, customer_id, origin, destination, cargo, weight_kg, pickup_date, eta_date, freight, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$no, $f['customer_id'], $f['origin'], $f['destination'], $f['cargo'], $f['weight_kg'], $f['pickup_date'], $f['eta_date'], $f['freight'], $u['id']]);
        $newId = (int)db()->lastInsertId();
        audit('create', 'order', $newId, $no);
        flash('Order ' . $no . ' dibuat. Lanjutkan dengan memilih armada dan driver.');
        redirect('order_view.php?id=' . $newId);
    }
}

$pageTitle = $order ? 'Ubah order' : 'Order baru';
require __DIR__ . '/includes/header.php';
?>
<h2><?= e($pageTitle) ?><?= $order ? ' · <span class="num">' . e($order['order_no']) . '</span>' : '' ?></h2>
<?php foreach ($errors as $er): ?><div class="flash f-bad"><?= e($er) ?></div><?php endforeach; ?>
<?php if (!$customers): ?>
  <div class="empty"><h3>Belum ada pelanggan aktif</h3><p>Tambahkan pelanggan dulu sebelum membuat order.</p><a class="btn primary" href="customers.php">Tambah pelanggan</a></div>
<?php else: ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="form-grid">
    <label>Pelanggan
      <select name="customer_id" required>
        <option value="">Pilih pelanggan</option>
        <?php foreach ($customers as $c): ?>
          <option value="<?= (int)$c['id'] ?>"<?= (int)$f['customer_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Jenis muatan <input name="cargo" required value="<?= e($f['cargo']) ?>"></label>
    <label>Kota asal <input name="origin" required value="<?= e($f['origin']) ?>"></label>
    <label>Kota tujuan <input name="destination" required value="<?= e($f['destination']) ?>"></label>
    <label>Berat (kg) <input type="number" name="weight_kg" min="1" step="1" required value="<?= e($f['weight_kg']) ?>"></label>
    <label>Tarif ke pelanggan (Rp) <input type="number" name="freight" min="0" step="1" required value="<?= e($f['freight']) ?>"></label>
    <label>Tanggal ambil <input type="date" name="pickup_date" required value="<?= e($f['pickup_date']) ?>"></label>
    <label>Estimasi tiba <input type="date" name="eta_date" required value="<?= e($f['eta_date']) ?>"></label>
  </div>
  <div class="actions">
    <a class="btn" href="<?= $order ? 'order_view.php?id=' . $id : 'orders.php' ?>">Batal</a>
    <button class="btn primary">Simpan order</button>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
