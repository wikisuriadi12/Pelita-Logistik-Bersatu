<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('finance', 'manajemen');
$canEdit = has_role('finance');
$pageTitle = 'Invoice';
$active = 'invoices';
$errors = [];

/* ---------- buat invoice ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        fail(403, 'Akun Anda hanya bisa melihat invoice.');
    }
    $customerId = (int)post('customer_id');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['order_ids'] ?? [])))));
    $taxRate = min(100, post_num('tax_rate'));
    $issue = post_date('issue_date') ?: date('Y-m-d');
    $due = post_date('due_date') ?: date('Y-m-d', strtotime('+30 days'));

    if (!$customerId || !q_val('SELECT id FROM customers WHERE id = ?', [$customerId])) $errors[] = 'Pilih pelanggan.';
    if (!$ids) $errors[] = 'Centang minimal satu order untuk ditagihkan.';
    if ($due < $issue) $errors[] = 'Jatuh tempo tidak boleh sebelum tanggal terbit.';

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $in = implode(',', array_fill(0, count($ids), '?'));
            $orders = q_all("SELECT id, freight FROM orders WHERE id IN ($in) AND customer_id = ? AND status = 'terkirim' AND invoice_id IS NULL FOR UPDATE", array_merge($ids, [$customerId]));
            if (count($orders) !== count($ids)) {
                $pdo->rollBack();
                $errors[] = 'Sebagian order sudah ditagihkan atau bukan milik pelanggan ini. Muat ulang halaman.';
            } else {
                $subtotal = array_sum(array_column($orders, 'freight'));
                $tax = round($subtotal * $taxRate / 100);
                $no = next_number('inv-' . date('ym'), 'INV-' . date('ym') . '-');
                q('INSERT INTO invoices (invoice_no, customer_id, issue_date, due_date, subtotal, tax_rate, tax_amount, total, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$no, $customerId, $issue, $due, $subtotal, $taxRate, $tax, $subtotal + $tax, post('notes'), $u['id']]);
                $invId = (int)$pdo->lastInsertId();
                q("UPDATE orders SET invoice_id = ? WHERE id IN ($in)", array_merge([$invId], $ids));
                $pdo->commit();
                audit('create', 'invoice', $invId, $no);
                flash('Invoice ' . $no . ' diterbitkan.');
                redirect('invoice_view.php?id=' . $invId);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e);
            $errors[] = 'Invoice gagal dibuat. Coba lagi.';
        }
    }
}

$customers = q_all('SELECT id, name FROM customers ORDER BY name');
$newMode = get('new') !== '' || $errors;
$custId = (int)(post('customer_id') ?: get('customer_id'));
$eligible = [];
if ($newMode && $custId) {
    $eligible = q_all("SELECT id, order_no, origin, destination, delivered_date, freight FROM orders WHERE customer_id = ? AND status = 'terkirim' AND invoice_id IS NULL ORDER BY delivered_date, id", [$custId]);
}

/* ---------- daftar ---------- */
$status = get('status');
$where = [];
$p = [];
if (in_array($status, ['terbit', 'lunas', 'batal'], true)) {
    $where[] = 'i.status = ?';
    $p[] = $status;
} elseif ($status === 'jatuh_tempo') {
    $where[] = "i.status = 'terbit' AND i.due_date < CURDATE()";
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$rows = q_all("SELECT i.*, c.name AS customer, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id),0) AS paid
  FROM invoices i JOIN customers c ON c.id = i.customer_id $w ORDER BY i.id DESC LIMIT 200", $p);

require __DIR__ . '/includes/header.php';
?>
<div class="bar"><h2>Invoice</h2><?php if ($canEdit): ?><a class="btn primary" href="invoices.php?new=1">Buat invoice</a><?php endif; ?></div>
<?php foreach ($errors as $er): ?><div class="flash f-bad"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($newMode && $canEdit): ?>
<section class="card">
  <h3>Invoice baru</h3>
  <form method="get" class="filters">
    <input type="hidden" name="new" value="1">
    <select name="customer_id" aria-label="Pelanggan" onchange="this.form.submit()">
      <option value="">Pilih pelanggan</option>
      <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $custId === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <noscript><button class="btn">Tampilkan order</button></noscript>
  </form>
  <?php if ($custId && !$eligible): ?>
    <p class="muted">Tidak ada order terkirim yang belum ditagihkan untuk pelanggan ini.</p>
  <?php elseif ($eligible): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="customer_id" value="<?= $custId ?>">
    <div class="tw"><table style="min-width:600px">
      <thead><tr><th><input type="checkbox" id="pick-all" aria-label="Pilih semua"></th><th>No. order</th><th>Rute</th><th>Tiba</th><th class="r">Tarif</th></tr></thead>
      <tbody>
      <?php foreach ($eligible as $o): ?>
        <tr>
          <td><input type="checkbox" name="order_ids[]" value="<?= (int)$o['id'] ?>" data-amount="<?= e($o['freight']) ?>" checked></td>
          <td><a class="num" href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_no']) ?></a></td>
          <td><?= e($o['origin']) ?> → <?= e($o['destination']) ?></td>
          <td class="num"><?= e(tgl($o['delivered_date'])) ?></td>
          <td class="r num"><?= e(rp($o['freight'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="4">Subtotal terpilih</td><td class="r num" id="picked-total"></td></tr></tfoot>
    </table></div>
    <div class="form-grid" style="margin-top:12px">
      <label>Tanggal terbit <input type="date" name="issue_date" value="<?= e(date('Y-m-d')) ?>" required></label>
      <label>Jatuh tempo <input type="date" name="due_date" value="<?= e(date('Y-m-d', strtotime('+30 days'))) ?>" required></label>
      <label>Pajak (%) <input type="number" name="tax_rate" min="0" max="100" step="0.01" value="<?= e(cfg('default_tax_rate', 0)) ?>"></label>
      <label>Catatan <input name="notes" maxlength="255"></label>
    </div>
    <div class="actions" style="margin-top:12px"><a class="btn" href="invoices.php">Batal</a><button class="btn primary">Terbitkan invoice</button></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<form method="get" class="filters">
  <select name="status" aria-label="Status invoice" onchange="this.form.submit()">
    <option value="">Semua invoice</option>
    <?php foreach (['terbit' => 'Terbit', 'jatuh_tempo' => 'Jatuh tempo', 'lunas' => 'Lunas', 'batal' => 'Dibatalkan'] as $k => $l): ?>
      <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="btn">Terapkan</button></noscript>
</form>

<?php if (!$rows): ?>
  <div class="empty"><h3>Belum ada invoice</h3><p>Invoice dibuat dari order yang sudah terkirim. Pilih pelanggan, centang ordernya, lalu terbitkan.</p></div>
<?php else: ?>
<div class="tw"><table>
  <thead><tr><th>No. invoice</th><th>Pelanggan</th><th>Terbit</th><th>Jatuh tempo</th><th class="r">Total</th><th class="r">Sisa</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $i): $sisa = $i['status'] === 'batal' ? 0 : $i['total'] - $i['paid']; ?>
    <tr>
      <td><a class="num" href="invoice_view.php?id=<?= (int)$i['id'] ?>"><?= e($i['invoice_no']) ?></a></td>
      <td><?= e($i['customer']) ?></td>
      <td class="num"><?= e(tgl($i['issue_date'])) ?></td>
      <td class="num"><?= e(tgl($i['due_date'])) ?></td>
      <td class="r num"><?= e(rp($i['total'])) ?></td>
      <td class="r num"><?= e(rp($sisa)) ?></td>
      <td><?php [$sl, $sc] = invoice_state($i); echo '<span class="chip ' . e($sc) . '">' . e($sl) . '</span>'; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
