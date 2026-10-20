<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('finance', 'manajemen');
$canEdit = has_role('finance');
$active = 'invoices';

$id = (int)get('id');
function load_invoice(int $id): ?array
{
    return q_row('SELECT i.*, c.name AS customer, c.address AS caddr, c.npwp AS cnpwp,
        COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id),0) AS paid
        FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = ?', [$id]);
}
$inv = load_invoice($id);
if (!$inv) {
    fail(404, 'Invoice tidak ditemukan.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        fail(403, 'Akun Anda hanya bisa melihat invoice.');
    }
    $do = post('do');
    $outstanding = $inv['total'] - $inv['paid'];
    try {
        if ($do === 'pay') {
            $amount = post_num('amount');
            $date = post_date('paid_date') ?: date('Y-m-d');
            if ($inv['status'] !== 'terbit') flash('Invoice ini tidak menerima pembayaran.', 'bad');
            elseif ($amount <= 0) flash('Isi jumlah pembayaran.', 'bad');
            elseif ($amount > $outstanding + 0.005) flash('Pembayaran melebihi sisa tagihan (' . rp($outstanding) . ').', 'bad');
            else {
                q('INSERT INTO payments (invoice_id, paid_date, amount, method, reference, created_by) VALUES (?,?,?,?,?,?)',
                    [$id, $date, $amount, mb_substr(post('method'), 0, 40), mb_substr(post('reference'), 0, 120), $u['id']]);
                if ($amount >= $outstanding - 0.005) {
                    q("UPDATE invoices SET status = 'lunas' WHERE id = ?", [$id]);
                }
                audit('pay', 'invoice', $id, rp($amount));
                flash($amount >= $outstanding - 0.005 ? 'Pembayaran dicatat. Invoice lunas.' : 'Pembayaran dicatat.');
            }
        } elseif ($do === 'del_pay') {
            if (!has_role()) { flash('Hanya admin yang bisa menghapus pembayaran.', 'bad'); }
            else {
                q('DELETE FROM payments WHERE id = ? AND invoice_id = ?', [(int)post('pay_id'), $id]);
                q("UPDATE invoices SET status = 'terbit' WHERE id = ? AND status = 'lunas'", [$id]);
                audit('pay-del', 'invoice', $id);
                flash('Pembayaran dihapus.');
            }
        } elseif ($do === 'cancel') {
            if ($inv['status'] === 'batal' || $inv['paid'] > 0) flash('Invoice yang sudah ada pembayarannya tidak bisa dibatalkan.', 'bad');
            else {
                $pdo = db();
                $pdo->beginTransaction();
                q("UPDATE invoices SET status = 'batal' WHERE id = ?", [$id]);
                q('UPDATE orders SET invoice_id = NULL WHERE invoice_id = ?', [$id]);
                $pdo->commit();
                audit('cancel', 'invoice', $id);
                flash('Invoice dibatalkan. Order bisa ditagihkan lagi.');
            }
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log($e);
        flash('Terjadi kesalahan di server. Coba lagi.', 'bad');
    }
    redirect('invoice_view.php?id=' . $id);
}

/* order yang ditagihkan: bila invoice batal, ambil dari riwayat audit tidak tersedia, jadi tampilkan hanya yang terkait */
$items = q_all('SELECT o.id, o.order_no, o.origin, o.destination, o.delivered_date, o.cargo, o.freight FROM orders o WHERE o.invoice_id = ? ORDER BY o.delivered_date, o.id', [$id]);
$pays = q_all('SELECT * FROM payments WHERE invoice_id = ? ORDER BY paid_date, id', [$id]);
$outstanding = $inv['status'] === 'batal' ? 0 : $inv['total'] - $inv['paid'];
[$stLabel, $stClass] = invoice_state($inv);

$pageTitle = 'Invoice ' . $inv['invoice_no'];
require __DIR__ . '/includes/header.php';
?>
<div class="bar no-print">
  <a class="btn sm" href="invoices.php">Kembali</a>
  <div class="row"><a class="btn primary" href="invoice_print.php?id=<?= (int)$id ?>&amp;print=1" target="_blank" rel="noopener">Cetak invoice</a></div>
</div>

<section class="card">
  <div class="invoice-head">
    <div>
      <h2><?= e(cfg('company_name', 'Pelita Logistik Bersatu')) ?></h2>
      <div class="muted"><?= nl2br(e((string)cfg('company_address', ''))) ?></div>
      <div class="muted"><?= e((string)cfg('company_phone', '')) ?></div>
    </div>
    <div style="text-align:right">
      <h2>INVOICE</h2>
      <div class="num"><?= e($inv['invoice_no']) ?></div>
      <span class="chip <?= e($stClass) ?>"><?= e($stLabel) ?></span>
    </div>
  </div>
  <dl class="kv">
    <div><dt>Ditagihkan kepada</dt><dd><b><?= e($inv['customer']) ?></b><br><?= e($inv['caddr']) ?><?= $inv['cnpwp'] ? '<br>NPWP ' . e($inv['cnpwp']) : '' ?></dd></div>
    <div><dt>Tanggal terbit</dt><dd class="num"><?= e(tgl($inv['issue_date'])) ?></dd></div>
    <div><dt>Jatuh tempo</dt><dd class="num"><?= e(tgl($inv['due_date'])) ?></dd></div>
  </dl>

  <div class="tw"><table style="min-width:560px">
    <thead><tr><th>No. order</th><th>Rincian</th><th>Tiba</th><th class="r">Jumlah</th></tr></thead>
    <tbody>
    <?php foreach ($items as $o): ?>
      <tr>
        <td class="num"><a href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_no']) ?></a></td>
        <td>Pengangkutan <?= e($o['cargo']) ?>, <?= e($o['origin']) ?> → <?= e($o['destination']) ?></td>
        <td class="num"><?= e(tgl($o['delivered_date'])) ?></td>
        <td class="r num"><?= e(rp($o['freight'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="4" class="muted">Invoice dibatalkan, order sudah dilepas.</td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="3">Subtotal</td><td class="r num"><?= e(rp($inv['subtotal'])) ?></td></tr>
      <tr><td colspan="3">Pajak (<?= e(rtrim(rtrim(number_format((float)$inv['tax_rate'], 2, ',', '.'), '0'), ',')) ?>%)</td><td class="r num"><?= e(rp($inv['tax_amount'])) ?></td></tr>
      <tr><td colspan="3">Total</td><td class="r num"><?= e(rp($inv['total'])) ?></td></tr>
      <tr><td colspan="3">Sudah dibayar</td><td class="r num"><?= e(rp($inv['paid'])) ?></td></tr>
      <tr><td colspan="3">Sisa tagihan</td><td class="r num"><?= e(rp($outstanding)) ?></td></tr>
    </tfoot>
  </table></div>
  <?php if ($inv['notes']): ?><p><?= e($inv['notes']) ?></p><?php endif; ?>
  <?php if (cfg('bank_info')): ?><p class="muted">Pembayaran ke: <?= e(cfg('bank_info')) ?></p><?php endif; ?>
</section>

<section class="card no-print">
  <h3>Pembayaran</h3>
  <?php if ($pays): ?>
  <div class="tw"><table style="min-width:520px">
    <thead><tr><th>Tanggal</th><th>Metode</th><th>Referensi</th><th class="r">Jumlah</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pays as $p): ?>
      <tr>
        <td class="num"><?= e(tgl($p['paid_date'])) ?></td><td><?= e($p['method']) ?></td><td><?= e($p['reference']) ?></td>
        <td class="r num"><?= e(rp($p['amount'])) ?></td>
        <td class="r"><?php if (has_role()): ?><form method="post" data-confirm="Hapus pembayaran ini?"><?= csrf_field() ?><input type="hidden" name="do" value="del_pay"><input type="hidden" name="pay_id" value="<?= (int)$p['id'] ?>"><button class="btn sm danger">Hapus</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="muted">Belum ada pembayaran.</p><?php endif; ?>

  <?php if ($canEdit && $inv['status'] === 'terbit'): ?>
  <form method="post" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="pay">
    <label>Tanggal bayar <input type="date" name="paid_date" value="<?= e(date('Y-m-d')) ?>" required></label>
    <label>Jumlah (Rp) <input type="number" name="amount" min="1" max="<?= e($outstanding) ?>" step="1" value="<?= e((int)round($outstanding)) ?>" required></label>
    <label>Metode <input name="method" placeholder="Transfer, tunai"></label>
    <label>Referensi <input name="reference" placeholder="No. bukti transfer"></label>
    <div class="actions" style="align-self:end"><button class="btn primary">Catat pembayaran</button></div>
  </form>
  <?php endif; ?>
  <?php if ($canEdit && $inv['status'] === 'terbit' && (float)$inv['paid'] == 0.0): ?>
  <form method="post" class="actions" data-confirm="Batalkan invoice ini? Order akan bisa ditagihkan lagi.">
    <?= csrf_field() ?><input type="hidden" name="do" value="cancel"><button class="btn danger">Batalkan invoice</button>
  </form>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
