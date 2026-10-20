<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/chart.php';
$u = require_login();
if ($u['role'] === 'driver') {
    redirect('orders.php');
}
$pageTitle = 'Ringkasan';
$active = 'dash';

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));

$aktif = (int)q_val("SELECT COUNT(*) FROM orders WHERE status IN ('baru','dijadwalkan','jalan')");
$jalan = (int)q_val("SELECT COUNT(*) FROM orders WHERE status = 'jalan'");
$vehTotal = (int)q_val('SELECT COUNT(*) FROM vehicles');
$vehFree = (int)q_val("SELECT COUNT(*) FROM vehicles WHERE status = 'tersedia'");
$rev = (float)q_val("SELECT COALESCE(SUM(freight),0) FROM orders WHERE status='terkirim' AND delivered_date >= ? AND delivered_date < ?", [$monthStart, $monthEnd]);
$cost = (float)q_val("SELECT COALESCE(SUM(c.amount),0) FROM order_costs c JOIN orders o ON o.id=c.order_id WHERE o.status='terkirim' AND o.delivered_date >= ? AND o.delivered_date < ?", [$monthStart, $monthEnd]);
$done = q_row("SELECT COUNT(*) n, COALESCE(SUM(delivered_date <= eta_date),0) ok FROM orders WHERE status='terkirim'");
$ontime = $done['n'] > 0 ? round(100 * $done['ok'] / $done['n']) : null;
$piutang = (float)q_val("SELECT COALESCE(SUM(i.total - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id=i.id),0)),0) FROM invoices i WHERE i.status='terbit'");

/* grafik 6 bulan */
$labels = []; $revs = []; $costs = [];
for ($i = 5; $i >= 0; $i--) {
    $m0 = date('Y-m-01', strtotime("first day of -$i month"));
    $m1 = date('Y-m-d', strtotime($m0 . ' +1 month'));
    $labels[] = bulan_pendek()[(int)date('n', strtotime($m0)) - 1];
    $revs[] = (float)q_val("SELECT COALESCE(SUM(freight),0) FROM orders WHERE status='terkirim' AND delivered_date >= ? AND delivered_date < ?", [$m0, $m1]);
    $costs[] = (float)q_val("SELECT COALESCE(SUM(c.amount),0) FROM order_costs c JOIN orders o ON o.id=c.order_id WHERE o.status='terkirim' AND o.delivered_date >= ? AND o.delivered_date < ?", [$m0, $m1]);
}
$hasChart = array_sum($revs) + array_sum($costs) > 0;

/* perlu ditindak */
$alerts = [];
foreach (q_all("SELECT id, order_no, status, pickup_date, eta_date FROM orders WHERE (status IN ('baru','dijadwalkan') AND pickup_date < ?) OR (status = 'jalan' AND eta_date < ?) ORDER BY pickup_date LIMIT 20", [$today, $today]) as $o) {
    $msg = $o['status'] === 'jalan'
        ? $o['order_no'] . ' melewati estimasi tiba ' . tgl($o['eta_date']) . '.'
        : $o['order_no'] . ($o['status'] === 'baru' ? ' belum dapat armada' : ' belum berangkat') . ', jadwal ambil ' . tgl($o['pickup_date']) . ' sudah lewat.';
    $alerts[] = ['bad', $msg, 'order_view.php?id=' . $o['id']];
}
foreach (q_all("SELECT plate, kir_expiry FROM vehicles WHERE kir_expiry IS NOT NULL AND kir_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY kir_expiry") as $v) {
    $alerts[] = [$v['kir_expiry'] < $today ? 'bad' : 'warn', 'KIR ' . $v['plate'] . ($v['kir_expiry'] < $today ? ' sudah habis ' : ' habis ') . tgl($v['kir_expiry']) . '.', 'vehicles.php'];
}
foreach (q_all("SELECT name, sim_expiry FROM drivers WHERE sim_expiry IS NOT NULL AND sim_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY sim_expiry") as $d) {
    $alerts[] = [$d['sim_expiry'] < $today ? 'bad' : 'warn', 'SIM ' . $d['name'] . ($d['sim_expiry'] < $today ? ' sudah habis ' : ' habis ') . tgl($d['sim_expiry']) . '.', 'drivers.php'];
}
$unbilled = (int)q_val("SELECT COUNT(*) FROM orders WHERE status='terkirim' AND invoice_id IS NULL");
if ($unbilled && has_role('finance', 'manajemen')) {
    $alerts[] = ['warn', $unbilled . ' order terkirim belum ditagihkan.', 'invoices.php?new=1'];
}
$overdue = (int)q_val("SELECT COUNT(*) FROM invoices i WHERE i.status='terbit' AND i.due_date < CURDATE()");
if ($overdue && has_role('finance', 'manajemen')) {
    $alerts[] = ['bad', $overdue . ' invoice sudah jatuh tempo dan belum lunas.', 'invoices.php?status=jatuh_tempo'];
}

$recent = q_all('SELECT o.id, o.order_no, o.origin, o.destination, o.pickup_date, o.freight, o.status, c.name AS customer FROM orders o JOIN customers c ON c.id=o.customer_id ORDER BY o.id DESC LIMIT 8');
$empty = !$vehTotal && !q_val('SELECT COUNT(*) FROM orders');

require __DIR__ . '/includes/header.php';
?>
<?php if ($empty): ?>
  <div class="empty">
    <h2>Belum ada data</h2>
    <p>Mulai dengan pelanggan, armada, dan driver. Setelah itu buat order pengiriman pertama dan ringkasan ini terisi otomatis.</p>
    <div class="row">
      <?php if (has_role('dispatcher', 'finance')): ?><a class="btn primary" href="customers.php">Tambah pelanggan</a><?php endif; ?>
      <?php if (has_role('dispatcher')): ?><a class="btn" href="vehicles.php">Tambah armada</a><a class="btn" href="drivers.php">Tambah driver</a><?php endif; ?>
    </div>
  </div>
<?php else: ?>
<div class="strip">
  <div><div class="k">Order aktif</div><div class="v num"><?= $aktif ?></div><div class="s"><?= $jalan ?> dalam perjalanan</div></div>
  <div><div class="k">Armada tersedia</div><div class="v num"><?= $vehFree ?>/<?= $vehTotal ?></div><div class="s">Siap dijadwalkan</div></div>
  <div><div class="k">Pendapatan bulan ini</div><div class="v num"><?= e(rp($rev)) ?></div><div class="s"><?= $rev > 0 ? 'Margin ' . round(100 * ($rev - $cost) / $rev) . '% setelah biaya' : 'Belum ada order terkirim' ?></div></div>
  <div><div class="k">Tepat waktu</div><div class="v num"><?= $ontime === null ? '-' : $ontime . '%' ?></div><div class="s"><?= (int)$done['n'] ?> order terkirim</div></div>
  <div><div class="k">Piutang berjalan</div><div class="v num"><?= e(rp($piutang)) ?></div><div class="s">Invoice terbit belum lunas</div></div>
</div>

<div class="cols">
  <section class="card">
    <h3>Pendapatan dan biaya, 6 bulan</h3>
    <?php if ($hasChart): ?>
      <?= chart_svg($labels, $revs, $costs) ?>
      <div class="legend"><span><i style="background:var(--accent)"></i>Pendapatan (tarif)</span><span><i style="background:var(--lamp)"></i>Biaya perjalanan</span></div>
    <?php else: ?>
      <p class="muted">Grafik muncul setelah ada order yang ditandai terkirim.</p>
    <?php endif; ?>
  </section>
  <section class="card">
    <h3>Perlu ditindak</h3>
    <?php if (!$alerts): ?>
      <p class="muted">Tidak ada yang perlu ditindak hari ini.</p>
    <?php else: ?>
      <ul class="alerts">
        <?php foreach ($alerts as [$tone, $msg, $href]): ?>
          <li><span class="dot <?= e($tone) ?>"></span><a href="<?= e($href) ?>"><?= e($msg) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <div class="bar"><h3>Order terbaru</h3><a class="btn sm" href="orders.php">Semua order</a></div>
  <?php if (!$recent): ?>
    <p class="muted">Belum ada order.</p>
  <?php else: ?>
  <div class="tw"><table>
    <thead><tr><th>No. order</th><th>Pelanggan</th><th>Rute</th><th>Ambil</th><th class="r">Tarif</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $o): ?>
      <tr>
        <td><a class="num" href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_no']) ?></a></td>
        <td><?= e($o['customer']) ?></td>
        <td><?= e($o['origin']) ?> → <?= e($o['destination']) ?></td>
        <td class="num"><?= e(tgl($o['pickup_date'])) ?></td>
        <td class="r num"><?= e(rp($o['freight'])) ?></td>
        <td><?= chip(order_status_map(), $o['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
