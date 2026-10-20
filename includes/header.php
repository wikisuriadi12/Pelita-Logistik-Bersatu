<?php
/** Kerangka atas halaman. Set $pageTitle dan $active sebelum include. */
$u = user();
$pageTitle = $pageTitle ?? cfg('company_name', 'Pelita TMS');
$active = $active ?? '';

$nav = [];
if ($u) {
    if ($u['role'] !== 'driver') {
        $nav[] = ['index.php', 'Ringkasan', 'dash'];
    }
    $nav[] = ['orders.php', $u['role'] === 'driver' ? 'Order saya' : 'Order', 'orders'];
    if (has_role('dispatcher', 'finance')) {
        $nav[] = ['customers.php', 'Pelanggan', 'customers'];
    }
    if (has_role('dispatcher', 'manajemen')) {
        $nav[] = ['vehicles.php', 'Armada', 'vehicles'];
        $nav[] = ['drivers.php', 'Driver', 'drivers'];
    }
    if (has_role('finance', 'manajemen')) {
        $nav[] = ['invoices.php', 'Invoice', 'invoices'];
        $nav[] = ['reports.php', 'Laporan', 'reports'];
    }
    if (has_role()) {
        $nav[] = ['users.php', 'Pengguna', 'users'];
    }
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · <?= e(cfg('company_name', 'Pelita TMS')) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="top">
  <div class="wrap">
    <div class="brand">
      <svg width="30" height="30" viewBox="0 0 28 28" aria-hidden="true"><rect x="7" y="9" width="14" height="14" rx="3" fill="var(--accent)"/><path d="M14 3c2.5 2.6 3.4 4.2 3.4 5.6A3.4 3.4 0 0 1 14 12a3.4 3.4 0 0 1-3.4-3.4C10.6 7.2 11.5 5.6 14 3z" fill="var(--lamp)"/><rect x="10" y="14" width="8" height="5" rx="1.5" fill="var(--lamp-soft)"/></svg>
      <div><b><?= e(cfg('company_name', 'Pelita TMS')) ?></b><small>Transportation Management System</small></div>
      <?php if ($u): ?>
      <form method="post" action="logout.php" class="who">
        <?= csrf_field() ?>
        <span><?= e($u['name']) ?> <em><?= e(role_map()[$u['role']] ?? $u['role']) ?></em></span>
        <button class="btn sm">Keluar</button>
      </form>
      <?php endif; ?>
    </div>
    <?php if ($nav): ?>
    <nav aria-label="Menu utama">
      <?php foreach ($nav as [$href, $label, $key]): ?>
        <a href="<?= e($href) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
<?php foreach (flash() as [$ft, $fm]): ?>
  <div class="flash <?= $ft === 'ok' ? 'f-ok' : 'f-bad' ?>" role="status"><?= e($fm) ?></div>
<?php endforeach; ?>
