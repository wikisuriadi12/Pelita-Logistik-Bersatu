<?php
/** Halaman cetak invoice (A4). Buka dengan ?print=1 agar dialog cetak langsung muncul. */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/terbilang.php';
$u = require_role('finance', 'manajemen');

$id = (int)get('id');
$inv = q_row('SELECT i.*, c.name AS customer, c.address AS caddr, c.npwp AS cnpwp, c.phone AS cphone,
    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id),0) AS paid
    FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = ?', [$id]);
if (!$inv) {
    fail(404, 'Invoice tidak ditemukan.');
}
$items = q_all('SELECT order_no, origin, destination, cargo, weight_kg, delivered_date, freight FROM orders WHERE invoice_id = ? ORDER BY delivered_date, id', [$id]);
$pays = q_all('SELECT paid_date, amount, method FROM payments WHERE invoice_id = ? ORDER BY paid_date, id', [$id]);
$outstanding = $inv['status'] === 'batal' ? 0 : max(0, $inv['total'] - $inv['paid']);
$mark = $inv['status'] === 'lunas' ? 'LUNAS' : ($inv['status'] === 'batal' ? 'BATAL' : '');
$taxLabel = rtrim(rtrim(number_format((float)$inv['tax_rate'], 2, ',', '.'), '0'), ',');
$bank = trim((string)cfg('bank_info', ''));
$signer = trim((string)cfg('invoice_signer', ''));
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice <?= e($inv['invoice_no']) ?></title>
<style>
  @page { size: A4; margin: 15mm 16mm; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #e9edf0; color: #111; font: 11pt/1.45 "Segoe UI", Arial, Helvetica, sans-serif; }
  .toolbar { max-width: 210mm; margin: 12px auto 0; padding: 0 12px; display: flex; gap: 8px; justify-content: flex-end; }
  .toolbar a, .toolbar button { font: inherit; font-size: 14px; border: 1px solid #b9c4cb; background: #fff; color: #111; padding: 7px 14px; border-radius: 6px; cursor: pointer; text-decoration: none; }
  .toolbar button { background: #0d5a6f; color: #fff; border-color: #0d5a6f; }
  .sheet { position: relative; width: 210mm; max-width: 100%; margin: 12px auto 32px; background: #fff; padding: 16mm; box-shadow: 0 1px 6px rgba(0,0,0,.15); }
  .head { display: flex; justify-content: space-between; gap: 20px; border-bottom: 2px solid #0d5a6f; padding-bottom: 10px; }
  .co b { font-size: 16pt; color: #0d5a6f; display: block; line-height: 1.2; }
  .co div { font-size: 10pt; color: #333; white-space: pre-line; }
  .title { text-align: right; }
  .title h1 { margin: 0; font-size: 20pt; letter-spacing: .08em; }
  .title div { font-size: 10.5pt; }
  .meta { display: grid; grid-template-columns: 1.4fr 1fr; gap: 18px; margin: 14px 0; }
  .meta h4 { margin: 0 0 2px; font-size: 9pt; letter-spacing: .06em; text-transform: uppercase; color: #555; }
  .meta p { margin: 0; }
  table { width: 100%; border-collapse: collapse; font-size: 10.5pt; }
  th { text-align: left; font-size: 9pt; letter-spacing: .05em; text-transform: uppercase; background: #eef3f5; border-top: 1px solid #333; border-bottom: 1px solid #333; padding: 6px 8px; }
  td { padding: 6px 8px; border-bottom: 1px solid #ccd5da; vertical-align: top; }
  .r { text-align: right; } .n { font-variant-numeric: tabular-nums; white-space: nowrap; }
  .sum { width: 62%; margin-left: auto; margin-top: 10px; }
  .sum td { border: 0; padding: 3px 8px; }
  .sum tr.total td { border-top: 1px solid #333; font-weight: 700; font-size: 12pt; }
  .words { margin: 10px 0; padding: 8px 10px; background: #f3f6f8; border-left: 3px solid #e8a11a; font-style: italic; }
  .foot { display: grid; grid-template-columns: 1.3fr 1fr; gap: 24px; margin-top: 16px; }
  .foot h4 { margin: 0 0 2px; font-size: 9pt; letter-spacing: .06em; text-transform: uppercase; color: #555; }
  .foot p { margin: 0 0 8px; white-space: pre-line; }
  .sign { text-align: center; }
  .sign .space { height: 22mm; }
  .sign .name { border-top: 1px solid #333; display: inline-block; min-width: 55mm; padding-top: 3px; font-weight: 600; }
  .mark { position: absolute; top: 46%; left: 50%; transform: translate(-50%, -50%) rotate(-22deg); font-size: 80pt; font-weight: 800; letter-spacing: .08em; color: rgba(13,90,111,.10); pointer-events: none; }
  .mark.batal { color: rgba(176,51,42,.14); }
  .small { font-size: 9.5pt; color: #444; }
  @media (max-width: 700px) { .sheet { padding: 14px; } .head, .meta, .foot { grid-template-columns: 1fr; flex-direction: column; } .title { text-align: left; } .sum { width: 100%; } .scroll { overflow-x: auto; } }
  @media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
    tr, .sum, .words, .foot { break-inside: avoid; }
    thead { display: table-header-group; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <a href="invoice_view.php?id=<?= (int)$id ?>">Kembali</a>
  <button type="button" onclick="window.print()">Cetak atau simpan PDF</button>
</div>

<div class="sheet">
  <?php if ($mark): ?><div class="mark <?= $inv['status'] === 'batal' ? 'batal' : '' ?>" aria-hidden="true"><?= e($mark) ?></div><?php endif; ?>

  <div class="head">
    <div class="co">
      <b><?= e(cfg('company_name', 'Pelita Logistik Bersatu')) ?></b>
      <?php if (cfg('company_address')): ?><div><?= e(cfg('company_address')) ?></div><?php endif; ?>
      <?php if (cfg('company_phone')): ?><div>Telp. <?= e(cfg('company_phone')) ?></div><?php endif; ?>
      <?php if (cfg('company_npwp')): ?><div>NPWP <?= e(cfg('company_npwp')) ?></div><?php endif; ?>
    </div>
    <div class="title">
      <h1>INVOICE</h1>
      <div class="n"><b><?= e($inv['invoice_no']) ?></b></div>
      <div>Tanggal <?= e(tgl($inv['issue_date'])) ?></div>
      <div>Jatuh tempo <?= e(tgl($inv['due_date'])) ?></div>
    </div>
  </div>

  <div class="meta">
    <div>
      <h4>Ditagihkan kepada</h4>
      <p><b><?= e($inv['customer']) ?></b></p>
      <?php if ($inv['caddr']): ?><p><?= e($inv['caddr']) ?></p><?php endif; ?>
      <?php if ($inv['cphone']): ?><p>Telp. <?= e($inv['cphone']) ?></p><?php endif; ?>
      <?php if ($inv['cnpwp']): ?><p>NPWP <?= e($inv['cnpwp']) ?></p><?php endif; ?>
    </div>
    <div>
      <h4>Ketentuan</h4>
      <p>Pembayaran sebelum <?= e(tgl($inv['due_date'])) ?></p>
      <?php if ($inv['notes']): ?><p><?= e($inv['notes']) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="scroll">
  <table>
    <thead><tr><th style="width:28px">No</th><th>Rincian pengiriman</th><th>Tiba</th><th class="r">Berat</th><th class="r">Jumlah</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i => $o): ?>
      <tr>
        <td class="n"><?= $i + 1 ?></td>
        <td><b class="n"><?= e($o['order_no']) ?></b><br>Pengangkutan <?= e($o['cargo']) ?><br><span class="small"><?= e($o['origin']) ?> → <?= e($o['destination']) ?></span></td>
        <td class="n"><?= e(tgl($o['delivered_date'])) ?></td>
        <td class="r n"><?= e(angka($o['weight_kg'])) ?> kg</td>
        <td class="r n"><?= e(rp($o['freight'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="5" class="small">Invoice dibatalkan. Order sudah dilepas dari invoice ini.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>

  <table class="sum">
    <tr><td>Subtotal</td><td class="r n"><?= e(rp($inv['subtotal'])) ?></td></tr>
    <?php if ($inv['tax_amount'] > 0): ?><tr><td>Pajak (<?= e($taxLabel) ?>%)</td><td class="r n"><?= e(rp($inv['tax_amount'])) ?></td></tr><?php endif; ?>
    <tr class="total"><td>Total tagihan</td><td class="r n"><?= e(rp($inv['total'])) ?></td></tr>
    <?php if ($inv['paid'] > 0): ?>
      <tr><td>Sudah dibayar</td><td class="r n"><?= e(rp($inv['paid'])) ?></td></tr>
      <tr><td><b>Sisa tagihan</b></td><td class="r n"><b><?= e(rp($outstanding)) ?></b></td></tr>
    <?php endif; ?>
  </table>

  <div class="words">Terbilang: <?= e(terbilang_rupiah($inv['total'])) ?></div>

  <div class="foot">
    <div>
      <?php if ($bank): ?><h4>Pembayaran ke</h4><p><?= e($bank) ?></p><?php endif; ?>
      <?php if ($pays): ?>
        <h4>Pembayaran diterima</h4>
        <p><?php foreach ($pays as $p): ?><?= e(tgl($p['paid_date'])) ?> · <?= e(rp($p['amount'])) ?><?= $p['method'] ? ' (' . e($p['method']) . ')' : '' ?><br><?php endforeach; ?></p>
      <?php endif; ?>
      <p class="small">Mohon cantumkan nomor invoice saat melakukan pembayaran.</p>
    </div>
    <div class="sign">
      <div>Hormat kami,</div>
      <div><b><?= e(cfg('company_name', 'Pelita Logistik Bersatu')) ?></b></div>
      <div class="space"></div>
      <div class="name"><?= e($signer !== '' ? $signer : ' ') ?></div>
      <?php if (cfg('invoice_signer_title')): ?><div class="small"><?= e(cfg('invoice_signer_title')) ?></div><?php endif; ?>
    </div>
  </div>
</div>

<?php if (get('print') === '1'): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
<?php endif; ?>
</body>
</html>
