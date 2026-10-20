<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/pod.php';
$u = require_login();
$active = 'orders';

function load_order(int $id): ?array
{
    return q_row('SELECT o.*, c.name AS customer, v.plate, v.type AS vtype, v.capacity_kg, d.name AS driver, i.invoice_no
        FROM orders o JOIN customers c ON c.id = o.customer_id
        LEFT JOIN vehicles v ON v.id = o.vehicle_id
        LEFT JOIN drivers d ON d.id = o.driver_id
        LEFT JOIN invoices i ON i.id = o.invoice_id
        WHERE o.id = ?', [$id]);
}

function release_resources(array $o): void
{
    if ($o['vehicle_id']) {
        q("UPDATE vehicles SET status = 'tersedia' WHERE id = ? AND status = 'bertugas'", [$o['vehicle_id']]);
    }
    if ($o['driver_id']) {
        q("UPDATE drivers SET status = 'siaga' WHERE id = ? AND status = 'bertugas'", [$o['driver_id']]);
    }
}

$id = (int)get('id');
$order = load_order($id);
if (!$order) {
    fail(404, 'Order tidak ditemukan.');
}

$isStaff = has_role('dispatcher', 'finance', 'manajemen');
$isOwnDriver = $u['role'] === 'driver' && $order['driver_id'] !== null && (int)$u['driver_id'] === (int)$order['driver_id'];
if (!$isStaff && !$isOwnDriver) {
    fail(403, 'Order ini bukan tugas Anda.');
}
$canDispatch = has_role('dispatcher');
$canMove = $canDispatch || $isOwnDriver;
$canCost = has_role('dispatcher', 'finance') || ($isOwnDriver && $order['status'] === 'jalan');
$canPod = $canDispatch || ($isOwnDriver && in_array($order['status'], ['jalan', 'terkirim'], true));
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = post('do');
    try {
        switch ($do) {
            case 'assign':
                if (!$canDispatch) { flash('Tidak punya akses.', 'bad'); break; }
                $vid = (int)post('vehicle_id');
                $did = (int)post('driver_id');
                $pdo = db();
                $pdo->beginTransaction();
                $o = q_row('SELECT status, weight_kg FROM orders WHERE id = ? FOR UPDATE', [$id]);
                $v = q_row('SELECT * FROM vehicles WHERE id = ? FOR UPDATE', [$vid]);
                $d = q_row('SELECT * FROM drivers WHERE id = ? FOR UPDATE', [$did]);
                $msg = null;
                if (!$o || $o['status'] !== 'baru') $msg = 'Order ini sudah tidak berstatus baru.';
                elseif (!$v || !$d) $msg = 'Pilih armada dan driver.';
                elseif ($v['status'] !== 'tersedia' || $d['status'] !== 'siaga') $msg = 'Armada atau driver baru saja dipakai order lain.';
                elseif ((int)$v['capacity_kg'] < (int)$o['weight_kg']) $msg = 'Kapasitas armada kurang dari berat muatan.';
                elseif ($v['kir_expiry'] && $v['kir_expiry'] < $today) $msg = 'KIR armada sudah habis. Perpanjang dulu.';
                elseif ($d['sim_expiry'] && $d['sim_expiry'] < $today) $msg = 'SIM driver sudah habis. Pilih driver lain.';
                if ($msg) {
                    $pdo->rollBack();
                    flash($msg, 'bad');
                    break;
                }
                q("UPDATE orders SET status = 'dijadwalkan', vehicle_id = ?, driver_id = ? WHERE id = ?", [$vid, $did, $id]);
                q("UPDATE vehicles SET status = 'bertugas' WHERE id = ?", [$vid]);
                q("UPDATE drivers SET status = 'bertugas' WHERE id = ?", [$did]);
                $pdo->commit();
                audit('assign', 'order', $id, $v['plate'] . ' / ' . $d['name']);
                flash('Order dijadwalkan.');
                break;

            case 'depart':
                if (!$canMove || $order['status'] !== 'dijadwalkan') { flash('Order belum bisa diberangkatkan.', 'bad'); break; }
                q("UPDATE orders SET status = 'jalan', departed_at = NOW() WHERE id = ? AND status = 'dijadwalkan'", [$id]);
                audit('depart', 'order', $id);
                flash('Order ditandai berangkat.');
                break;

            case 'deliver':
                if (!$canMove || $order['status'] !== 'jalan') { flash('Order belum dalam perjalanan.', 'bad'); break; }
                $date = post_date('delivered_date');
                $receiver = post('receiver_name');
                if (!$date || $date > $today) { flash('Isi tanggal tiba yang valid (tidak di masa depan).', 'bad'); break; }
                if ($receiver === '') { flash('Isi nama penerima barang.', 'bad'); break; }
                [$n, $errs] = save_pod_files($id);
                foreach ($errs as $er) flash($er, 'bad');
                $have = (int)q_val('SELECT COUNT(*) FROM pod_files WHERE order_id = ?', [$id]);
                if ($have < 1) { flash('Unggah minimal satu bukti pengiriman (foto surat jalan atau tanda terima).', 'bad'); break; }
                q("UPDATE orders SET status = 'terkirim', delivered_date = ?, receiver_name = ? WHERE id = ? AND status = 'jalan'", [$date, $receiver, $id]);
                release_resources($order);
                audit('deliver', 'order', $id, 'penerima ' . $receiver);
                flash('Order ditandai terkirim.');
                break;

            case 'cancel':
                if (!$canDispatch || !in_array($order['status'], ['baru', 'dijadwalkan', 'jalan'], true)) { flash('Order tidak bisa dibatalkan.', 'bad'); break; }
                q("UPDATE orders SET status = 'batal' WHERE id = ?", [$id]);
                release_resources($order);
                audit('cancel', 'order', $id);
                flash('Order dibatalkan.');
                break;

            case 'add_cost':
                if (!$canCost) { flash('Tidak punya akses.', 'bad'); break; }
                $cat = post('category');
                $amount = post_num('amount');
                if (!isset(cost_category_map()[$cat]) || $amount <= 0) { flash('Pilih kategori dan isi jumlah biaya.', 'bad'); break; }
                $vendorId = null;
                if (has_role('dispatcher', 'finance') && (int)post('vendor_id') > 0) {
                    $vendorId = q_val('SELECT id FROM vendors WHERE id = ? AND active = 1', [(int)post('vendor_id')]);
                    if ($vendorId === null) { flash('Vendor tidak ditemukan atau sudah nonaktif.', 'bad'); break; }
                    $vendorId = (int)$vendorId;
                }
                q('INSERT INTO order_costs (order_id, category, vendor_id, amount, note, created_by) VALUES (?,?,?,?,?,?)',
                    [$id, $cat, $vendorId, $amount, mb_substr(post('note'), 0, 200), $u['id']]);
                audit('cost', 'order', $id, $cat . ' ' . $amount);
                flash('Biaya dicatat.');
                break;

            case 'del_cost':
                if (!$canCost) { flash('Tidak punya akses.', 'bad'); break; }
                q('DELETE FROM order_costs WHERE id = ? AND order_id = ?', [(int)post('cost_id'), $id]);
                audit('cost-del', 'order', $id);
                flash('Biaya dihapus.');
                break;

            case 'upload_pod':
                if (!$canPod) { flash('Tidak punya akses.', 'bad'); break; }
                [$n, $errs] = save_pod_files($id);
                foreach ($errs as $er) flash($er, 'bad');
                if ($n) { audit('pod', 'order', $id, $n . ' file'); flash($n . ' file bukti diunggah.'); }
                elseif (!$errs) flash('Pilih file dulu.', 'bad');
                break;

            case 'del_pod':
                if (!has_role()) { flash('Hanya admin yang bisa menghapus bukti.', 'bad'); break; }
                $pod = q_row('SELECT * FROM pod_files WHERE id = ? AND order_id = ?', [(int)post('pod_id'), $id]);
                if ($pod) {
                    q('DELETE FROM pod_files WHERE id = ?', [$pod['id']]);
                    @unlink(pod_path($pod['filename']));
                    audit('pod-del', 'order', $id, $pod['original_name']);
                    flash('Bukti dihapus.');
                }
                break;
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log($e);
        flash('Terjadi kesalahan di server. Coba lagi.', 'bad');
    }
    redirect('order_view.php?id=' . $id);
}

$costs = q_all('SELECT x.*, v.name AS vendor FROM order_costs x LEFT JOIN vendors v ON v.id = x.vendor_id WHERE x.order_id = ? ORDER BY x.id', [$id]);
$vendorOpts = has_role('dispatcher', 'finance') ? q_all('SELECT id, name, type FROM vendors WHERE active = 1 ORDER BY name') : [];
$costTotal = array_sum(array_column($costs, 'amount'));
$pods = q_all('SELECT * FROM pod_files WHERE order_id = ? ORDER BY id', [$id]);
$trail = q_all('SELECT a.*, u.name AS who FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.entity = ? AND a.entity_id = ? ORDER BY a.id DESC LIMIT 15', ['order', $id]);

$vehOpts = $drvOpts = [];
if ($order['status'] === 'baru' && $canDispatch) {
    $vehOpts = q_all("SELECT * FROM vehicles WHERE status = 'tersedia' ORDER BY plate");
    $drvOpts = q_all("SELECT * FROM drivers WHERE status = 'siaga' ORDER BY name");
}

$pageTitle = 'Order ' . $order['order_no'];
require __DIR__ . '/includes/header.php';
$st = $order['status'];
?>
<div class="bar">
  <div>
    <div class="muted num"><?= e($order['order_no']) ?></div>
    <h2><?= e($order['customer']) ?></h2>
  </div>
  <div class="row"><?= chip(order_status_map(), $st) ?><a class="btn sm" href="<?= $isOwnDriver && !$isStaff ? 'orders.php' : 'orders.php' ?>">Kembali</a></div>
</div>

<section class="card">
  <dl class="kv">
    <div><dt>Rute</dt><dd><?= e($order['origin']) ?> → <?= e($order['destination']) ?></dd></div>
    <div><dt>Muatan</dt><dd><?= e($order['cargo']) ?></dd></div>
    <div><dt>Berat</dt><dd class="num"><?= e(angka($order['weight_kg'])) ?> kg</dd></div>
    <div><dt>Tarif</dt><dd class="num"><?= e(rp($order['freight'])) ?></dd></div>
    <div><dt>Ambil</dt><dd class="num"><?= e(tgl($order['pickup_date'])) ?></dd></div>
    <div><dt>Estimasi tiba</dt><dd class="num"><?= e(tgl($order['eta_date'])) ?></dd></div>
    <div><dt>Armada</dt><dd><?= $order['plate'] ? '<span class="num">' . e($order['plate']) . '</span> · ' . e($order['vtype']) : '-' ?></dd></div>
    <div><dt>Driver</dt><dd><?= e($order['driver'] ?? '-') ?></dd></div>
    <?php if ($st === 'terkirim'): ?>
      <div><dt>Tiba aktual</dt><dd class="num"><?= e(tgl($order['delivered_date'])) ?><?= $order['delivered_date'] > $order['eta_date'] ? ' (terlambat)' : '' ?></dd></div>
      <div><dt>Penerima</dt><dd><?= e($order['receiver_name']) ?></dd></div>
      <div><dt>Invoice</dt><dd><?= $order['invoice_no'] ? '<a class="num" href="invoice_view.php?id=' . (int)$order['invoice_id'] . '">' . e($order['invoice_no']) . '</a>' : '<span class="muted">Belum ditagihkan</span>' ?></dd></div>
    <?php endif; ?>
  </dl>

  <?php if ($st === 'baru' && $canDispatch): ?>
  <div class="sect">
    <h3>Jadwalkan pengiriman</h3>
    <?php if ($vehOpts && $drvOpts): ?>
    <form method="post" class="form-grid">
      <?= csrf_field() ?><input type="hidden" name="do" value="assign">
      <label>Armada
        <select name="vehicle_id" required>
          <option value="">Pilih armada</option>
          <?php foreach ($vehOpts as $v): $low = (int)$v['capacity_kg'] < (int)$order['weight_kg']; ?>
            <option value="<?= (int)$v['id'] ?>"<?= $low ? ' disabled' : '' ?>><?= e($v['plate'] . ' · ' . $v['type'] . ' · ' . angka($v['capacity_kg']) . ' kg' . ($low ? ' (kapasitas kurang)' : '')) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Driver
        <select name="driver_id" required>
          <option value="">Pilih driver</option>
          <?php foreach ($drvOpts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <div class="actions" style="align-self:end"><button class="btn primary">Jadwalkan</button></div>
    </form>
    <?php else: ?>
      <p class="muted"><?= !$vehOpts ? 'Belum ada armada tersedia. ' : '' ?><?= !$drvOpts ? 'Belum ada driver siaga.' : '' ?></p>
    <?php endif; ?>
    <div class="actions"><a class="btn" href="order_form.php?id=<?= $id ?>">Ubah order</a></div>
  </div>
  <?php endif; ?>

  <?php if ($st === 'dijadwalkan' && $canMove): ?>
  <div class="sect">
    <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="do" value="depart"><button class="btn primary">Tandai berangkat</button></form>
  </div>
  <?php endif; ?>

  <?php if ($st === 'jalan' && $canMove): ?>
  <div class="sect">
    <h3>Selesaikan pengiriman</h3>
    <form method="post" enctype="multipart/form-data" class="form-grid">
      <?= csrf_field() ?><input type="hidden" name="do" value="deliver">
      <label>Tanggal tiba <input type="date" name="delivered_date" value="<?= e($today) ?>" max="<?= e($today) ?>" required></label>
      <label>Nama penerima <input name="receiver_name" required></label>
      <label>Bukti pengiriman (JPG, PNG, PDF) <input type="file" name="pod[]" accept="image/jpeg,image/png,application/pdf" multiple<?= $pods ? '' : ' required' ?>></label>
      <div class="actions" style="align-self:end"><button class="btn primary">Tandai terkirim</button></div>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($canDispatch && in_array($st, ['baru', 'dijadwalkan', 'jalan'], true)): ?>
  <div class="sect">
    <form method="post" class="actions" data-confirm="Batalkan order ini? Armada dan driver akan dibebaskan.">
      <?= csrf_field() ?><input type="hidden" name="do" value="cancel"><button class="btn danger">Batalkan order</button>
    </form>
  </div>
  <?php endif; ?>
</section>

<div class="cols">
  <section class="card">
    <h3>Biaya perjalanan</h3>
    <?php if ($costs): ?>
    <div class="tw"><table style="min-width:0">
      <thead><tr><th>Kategori</th><th>Catatan</th><th class="r">Jumlah</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($costs as $c): ?>
        <tr>
          <td><?= e(cost_category_map()[$c['category']] ?? $c['category']) ?></td>
          <td><?= e($c['note']) ?><?= $c['vendor'] ? '<br><span class="muted">Vendor: ' . e($c['vendor']) . '</span>' : '' ?></td>
          <td class="r num"><?= e(rp($c['amount'])) ?></td>
          <td class="r"><?php if ($canCost): ?>
            <form method="post" data-confirm="Hapus biaya ini?"><?= csrf_field() ?><input type="hidden" name="do" value="del_cost"><input type="hidden" name="cost_id" value="<?= (int)$c['id'] ?>"><button class="btn sm danger">Hapus</button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="2">Total biaya</td><td class="r num"><?= e(rp($costTotal)) ?></td><td></td></tr>
        <tr><td colspan="2">Margin (tarif dikurangi biaya)</td><td class="r num"><?= e(rp($order['freight'] - $costTotal)) ?></td><td></td></tr></tfoot>
    </table></div>
    <?php else: ?>
      <p class="muted">Belum ada biaya tercatat.</p>
    <?php endif; ?>
    <?php if ($canCost): ?>
    <form method="post" class="form-grid">
      <?= csrf_field() ?><input type="hidden" name="do" value="add_cost">
      <label>Kategori
        <select name="category"><?php foreach (cost_category_map() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
      </label>
      <label>Jumlah (Rp) <input type="number" name="amount" min="1" step="1" required></label>
      <?php if ($vendorOpts): ?>
      <label>Vendor (opsional)
        <select name="vendor_id"><option value="">Tanpa vendor</option>
          <?php foreach ($vendorOpts as $vo): ?><option value="<?= (int)$vo['id'] ?>"><?= e($vo['name'] . ' · ' . $vo['type']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <label>Catatan <input name="note" maxlength="200"></label>
      <div class="actions" style="align-self:end"><button class="btn">Tambah biaya</button></div>
    </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <h3>Bukti pengiriman</h3>
    <?php if ($pods): ?>
    <div class="pods">
      <?php foreach ($pods as $p): ?>
        <div>
          <a href="pod.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener">
            <?php if ($p['mime'] === 'application/pdf'): ?><div class="doc">PDF</div><?php else: ?><img src="pod.php?id=<?= (int)$p['id'] ?>" alt="<?= e($p['original_name']) ?>" loading="lazy"><?php endif; ?>
            <small><?= e($p['original_name']) ?></small>
          </a>
          <?php if (has_role()): ?>
            <form method="post" data-confirm="Hapus bukti ini?"><?= csrf_field() ?><input type="hidden" name="do" value="del_pod"><input type="hidden" name="pod_id" value="<?= (int)$p['id'] ?>"><button class="btn sm danger" style="margin-top:6px">Hapus</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p class="muted">Belum ada bukti. Bukti wajib diunggah saat order ditandai terkirim.</p>
    <?php endif; ?>
    <?php if ($canPod): ?>
    <form method="post" enctype="multipart/form-data" class="row">
      <?= csrf_field() ?><input type="hidden" name="do" value="upload_pod">
      <input type="file" name="pod[]" accept="image/jpeg,image/png,application/pdf" multiple required style="flex:1 1 200px">
      <button class="btn">Unggah</button>
    </form>
    <?php endif; ?>
  </section>
</div>

<?php if ($isStaff && $trail): ?>
<section class="card">
  <h3>Riwayat</h3>
  <ul class="alerts">
    <?php foreach ($trail as $t): ?>
      <li><span class="dot warn"></span><span><b><?= e($t['action']) ?></b> oleh <?= e($t['who'] ?? 'sistem') ?>, <span class="num"><?= e($t['created_at']) ?></span><?= $t['detail'] ? ' · ' . e($t['detail']) : '' ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
