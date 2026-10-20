<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('finance', 'manajemen');
$pageTitle = 'Laporan';
$active = 'reports';

$m = get('m', date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
    $m = date('Y-m');
}
$start = $m . '-01';
$end = date('Y-m-d', strtotime($start . ' +1 month'));
$range = [$start, $end];

/* order terkirim pada bulan terpilih, lengkap dengan biaya */
$orders = q_all("SELECT o.*, c.name AS customer, v.plate, d.name AS driver, i.invoice_no,
    COALESCE((SELECT SUM(x.amount) FROM order_costs x WHERE x.order_id = o.id),0) AS cost
  FROM orders o JOIN customers c ON c.id = o.customer_id
  LEFT JOIN vehicles v ON v.id = o.vehicle_id LEFT JOIN drivers d ON d.id = o.driver_id
  LEFT JOIN invoices i ON i.id = o.invoice_id
  WHERE o.status = 'terkirim' AND o.delivered_date >= ? AND o.delivered_date < ?
  ORDER BY o.delivered_date, o.id", $range);

/* piutang semua invoice terbit */
$recv = q_all("SELECT i.invoice_no, i.issue_date, i.due_date, i.total, c.name AS customer,
    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id),0) AS paid
  FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.status = 'terbit' ORDER BY i.due_date");

$export = get('export');
if ($export === 'order') {
    $rows = [];
    foreach ($orders as $o) {
        $rows[] = [$o['order_no'], $o['pickup_date'], $o['delivered_date'], $o['customer'], $o['origin'], $o['destination'], $o['plate'], $o['driver'],
            $o['weight_kg'], (float)$o['freight'], (float)$o['cost'], (float)$o['freight'] - (float)$o['cost'], $o['delivered_date'] <= $o['eta_date'] ? 'Ya' : 'Tidak', $o['invoice_no']];
    }
    csv_out('laporan-order-' . $m . '.csv', ['No. order', 'Ambil', 'Tiba', 'Pelanggan', 'Asal', 'Tujuan', 'Plat', 'Driver', 'Berat kg', 'Tarif', 'Biaya', 'Margin', 'Tepat waktu', 'Invoice'], $rows);
}
if ($export === 'piutang') {
    $rows = [];
    foreach ($recv as $r) {
        $rows[] = [$r['invoice_no'], $r['customer'], $r['issue_date'], $r['due_date'], (float)$r['total'], (float)$r['paid'], (float)$r['total'] - (float)$r['paid'], max(0, (int)floor((time() - strtotime($r['due_date'])) / 86400))];
    }
    csv_out('piutang-' . date('Y-m-d') . '.csv', ['No. invoice', 'Pelanggan', 'Terbit', 'Jatuh tempo', 'Total', 'Dibayar', 'Sisa', 'Hari terlambat'], $rows);
}

$n = count($orders);
$rev = array_sum(array_column($orders, 'freight'));
$cost = array_sum(array_column($orders, 'cost'));
$ontime = $n ? round(100 * count(array_filter($orders, function ($o) { return $o['delivered_date'] <= $o['eta_date']; })) / $n) : null;

$byCustomer = [];
$byVehicle = [];
$add = function (array &$bucket, string $key, array $o) {
    if (!isset($bucket[$key])) {
        $bucket[$key] = ['n' => 0, 'rev' => 0, 'cost' => 0];
    }
    $bucket[$key]['n']++;
    $bucket[$key]['rev'] += $o['freight'];
    $bucket[$key]['cost'] += $o['cost'];
};
foreach ($orders as $o) {
    $add($byCustomer, (string)$o['customer'], $o);
    $add($byVehicle, (string)($o['plate'] ?: '-'), $o);
}
uasort($byCustomer, function ($a, $b) { return $b['rev'] <=> $a['rev']; });
uasort($byVehicle, function ($a, $b) { return $b['rev'] <=> $a['rev']; });

$byCat = q_all("SELECT x.category, SUM(x.amount) AS total FROM order_costs x JOIN orders o ON o.id = x.order_id
  WHERE o.status = 'terkirim' AND o.delivered_date >= ? AND o.delivered_date < ? GROUP BY x.category ORDER BY total DESC", $range);

$aging = ['Belum jatuh tempo' => 0, '1-30 hari' => 0, '31-60 hari' => 0, 'Lebih dari 60 hari' => 0];
$recvTotal = 0;
foreach ($recv as $r) {
    $left = $r['total'] - $r['paid'];
    if ($left <= 0.005) continue;
    $recvTotal += $left;
    $late = (int)floor((time() - strtotime($r['due_date'])) / 86400);
    $key = $late <= 0 ? 'Belum jatuh tempo' : ($late <= 30 ? '1-30 hari' : ($late <= 60 ? '31-60 hari' : 'Lebih dari 60 hari'));
    $aging[$key] += $left;
}

require __DIR__ . '/includes/header.php';
?>
<div class="bar no-print">
  <h2>Laporan <?= e(bulan_label($m)) ?></h2>
  <form method="get" class="filters">
    <input type="month" name="m" value="<?= e($m) ?>" aria-label="Bulan laporan">
    <button class="btn">Tampilkan</button>
    <a class="btn" href="reports.php?m=<?= e($m) ?>&export=order">Ekspor order (Excel)</a>
    <a class="btn" href="reports.php?export=piutang">Ekspor piutang (Excel)</a>
    <button type="button" class="btn" onclick="window.print()">Cetak / simpan PDF</button>
  </form>
</div>
<h2 class="print-only">Laporan <?= e(bulan_label($m)) ?> · <?= e(cfg('company_name')) ?></h2>

<div class="strip">
  <div><div class="k">Order terkirim</div><div class="v num"><?= $n ?></div><div class="s">Tepat waktu <?= $ontime === null ? '-' : $ontime . '%' ?></div></div>
  <div><div class="k">Pendapatan</div><div class="v num"><?= e(rp($rev)) ?></div><div class="s">Total tarif</div></div>
  <div><div class="k">Biaya perjalanan</div><div class="v num"><?= e(rp($cost)) ?></div><div class="s"><?= $rev > 0 ? round(100 * $cost / $rev) . '% dari pendapatan' : '-' ?></div></div>
  <div><div class="k">Margin</div><div class="v num"><?= e(rp($rev - $cost)) ?></div><div class="s"><?= $rev > 0 ? round(100 * ($rev - $cost) / $rev) . '%' : '-' ?></div></div>
</div>

<?php if (!$n): ?>
  <div class="empty"><h3>Tidak ada order terkirim di <?= e(bulan_label($m)) ?></h3><p>Pilih bulan lain, atau tandai order sebagai terkirim lebih dulu.</p></div>
<?php else: ?>
<div class="cols">
  <section class="card"><h3>Per pelanggan</h3>
    <div class="tw"><table style="min-width:0"><thead><tr><th>Pelanggan</th><th class="r">Order</th><th class="r">Pendapatan</th><th class="r">Margin</th></tr></thead><tbody>
    <?php foreach ($byCustomer as $k => $b): ?><tr><td><?= e($k) ?></td><td class="r num"><?= $b['n'] ?></td><td class="r num"><?= e(rp($b['rev'])) ?></td><td class="r num"><?= e(rp($b['rev'] - $b['cost'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
  <section class="card"><h3>Per armada</h3>
    <div class="tw"><table style="min-width:0"><thead><tr><th>Plat</th><th class="r">Trip</th><th class="r">Pendapatan</th><th class="r">Margin</th></tr></thead><tbody>
    <?php foreach ($byVehicle as $k => $b): ?><tr><td class="num"><?= e($k) ?></td><td class="r num"><?= $b['n'] ?></td><td class="r num"><?= e(rp($b['rev'])) ?></td><td class="r num"><?= e(rp($b['rev'] - $b['cost'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
</div>

<section class="card"><h3>Biaya per kategori</h3>
  <?php if (!$byCat): ?><p class="muted">Belum ada biaya tercatat untuk order bulan ini.</p><?php else: ?>
  <div class="tw"><table style="min-width:0"><thead><tr><th>Kategori</th><th class="r">Jumlah</th><th class="r">Porsi</th></tr></thead><tbody>
  <?php foreach ($byCat as $c): ?><tr><td><?= e(cost_category_map()[$c['category']] ?? $c['category']) ?></td><td class="r num"><?= e(rp($c['total'])) ?></td><td class="r num"><?= $cost > 0 ? round(100 * $c['total'] / $cost) : 0 ?>%</td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>

<section class="card"><h3>Daftar order terkirim</h3>
  <div class="tw"><table><thead><tr><th>No. order</th><th>Tiba</th><th>Pelanggan</th><th>Rute</th><th class="r">Tarif</th><th class="r">Biaya</th><th class="r">Margin</th><th>Invoice</th></tr></thead><tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td><a class="num" href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_no']) ?></a></td>
      <td class="num"><?= e(tgl($o['delivered_date'])) ?></td><td><?= e($o['customer']) ?></td>
      <td><?= e($o['origin']) ?> → <?= e($o['destination']) ?></td>
      <td class="r num"><?= e(rp($o['freight'])) ?></td><td class="r num"><?= e(rp($o['cost'])) ?></td><td class="r num"><?= e(rp($o['freight'] - $o['cost'])) ?></td>
      <td class="num"><?= e($o['invoice_no'] ?: '-') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<section class="card"><h3>Umur piutang</h3>
  <?php if ($recvTotal <= 0): ?><p class="muted">Tidak ada piutang berjalan.</p><?php else: ?>
  <div class="tw"><table style="min-width:0"><thead><tr><th>Kelompok</th><th class="r">Sisa tagihan</th></tr></thead><tbody>
  <?php foreach ($aging as $k => $v): ?><tr><td><?= e($k) ?></td><td class="r num"><?= e(rp($v)) ?></td></tr><?php endforeach; ?>
  </tbody><tfoot><tr><td>Total piutang</td><td class="r num"><?= e(rp($recvTotal)) ?></td></tr></tfoot></table></div><?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
