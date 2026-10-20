// Konfirmasi sebelum aksi yang tidak bisa dibatalkan: <form data-confirm="Pesan">
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) e.preventDefault();
});

// Halaman buat invoice: hitung total order yang dicentang.
(function () {
  var boxes = document.querySelectorAll('input[data-amount]');
  var out = document.getElementById('picked-total');
  if (!boxes.length || !out) return;
  function fmt(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }
  function update() {
    var t = 0;
    boxes.forEach(function (b) { if (b.checked) t += parseFloat(b.getAttribute('data-amount')) || 0; });
    out.textContent = fmt(t);
  }
  boxes.forEach(function (b) { b.addEventListener('change', update); });
  var all = document.getElementById('pick-all');
  if (all) all.addEventListener('change', function () {
    boxes.forEach(function (b) { b.checked = all.checked; });
    update();
  });
  update();
})();
