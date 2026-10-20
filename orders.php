<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('dispatcher', 'finance', 'manajemen', 'driver');
$pageTitle = $u['role'] === 'driver' ? 'Order saya' : 'Order';
$active = 'orders';

$status = get('status');
$qs = get('q');
$page = max(1, (int)get('page', '1'));
$per = 25;

$where = [];
$p = [];
if ($u['role'] === 'driver') {
    $where[] = 'o.driver_id = ?';
    $p[] = (int)$u['driver_id'];
}
if (array_key_exists($status, order_status_map())) {
    $where[] = 'o.status = ?';
    $p[] = $status;
}
if ($qs !== '') {
    $where[] = '(o.order_no LIKE ? OR c.name LIKE ? OR o.origin LIKE ? OR o.destination LIKE ?)';
    $like = '%' . $qs . '%';
    array_push($p, $like, $like, $like, $like);
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)q_val("SELECT COUNT(*) FROM orders o JOIN customers c ON c.id=o.customer_id $w", $p);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$off = ($page - 1) * $per;
$rows = q_all("SELECT o.*, c.name AS customer, v.plate, d.name AS driver
  FROM orders o JOIN customers c ON c.id=o.customer_id
  LEFT JOIN vehicles v ON v.id=o.vehicle_id LEFT JOIN drivers d ON d.id=o.driver_id
  $w ORDER BY o.pickup_date DESC, o.id DESC LIMIT $per OFFSET $off", $p);

function page_link(int $pg): string
{
    $q = array_filter(['status' => get('status'), 'q' => get('q'), 'page' => $pg > 1 ? $pg : null], function ($v) {
        return $v !== null && $v !== '';
    });
    return 'orders.php' . ($q ? '?' . http_build_query($q) : '');
}

require __DIR__ . '/includes/header.php';
?>
<div class="bar">
  <h2><?= e($pageTitle) ?></h2>
  <?php if (has_role('dispatcher')): ?><a class="btn primary" href="order_form.php">Order baru</a><?php endif; ?>
</div>

<form method="get" class="filters">
  <input type="search" name="q" value="<?= e($qs) ?>" placeholder="Cari nomor, pelanggan, rute" aria-label="Cari order">
  <select name="status" aria-label="Status">
    <option value="">Semua status</option>
    <?php foreach (order_status_map() as $k => $m): ?>
      <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($m[0]) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn">Terapkan</button>
</form>

<?php if (!$rows): ?>
  <div class="empty">
    <h3><?= $total === 0 && !$where ? 'Belum ada order' : 'Tidak ada order yang cocok' ?></h3>
    <p><?= $where ? 'Ubah filter atau kata kunci pencarian.' : 'Buat order pengiriman pertama, lalu jadwalkan armada dan driver.' ?></p>
  </div>
<?php else: ?>
<div class="tw"><table>
  <thead><tr><th>No. order</th><th>Pelanggan</th><th>Rute</th><th>Ambil / tiba</th><th class="r">Muatan</th><th>Armada · Driver</th><th class="r">Tarif</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $o): ?>
    <tr>
      <td><a class="num" href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_no']) ?></a></td>
      <td><?= e($o['customer']) ?></td>
      <td><?= e($o['origin']) ?> → <?= e($o['destination']) ?></td>
      <td class="num"><?= e(tgl($o['pickup_date'])) ?><br><?= e(tgl($o['eta_date'])) ?></td>
      <td class="r num"><?= e(angka($o['weight_kg'])) ?> kg</td>
      <td><?= $o['plate'] ? '<span class="num">' . e($o['plate']) . '</span><br><span class="muted">' . e($o['driver']) . '</span>' : '<span class="muted">Belum ditugaskan</span>' ?></td>
      <td class="r num"><?= e(rp($o['freight'])) ?></td>
      <td><?= chip(order_status_map(), $o['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a class="btn sm" href="<?= e(page_link($page - 1)) ?>">Sebelumnya</a><?php endif; ?>
  <span class="muted">Halaman <?= $page ?> dari <?= $pages ?> (<?= $total ?> order)</span>
  <?php if ($page < $pages): ?><a class="btn sm" href="<?= e(page_link($page + 1)) ?>">Berikutnya</a><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
