<?php
/** Angka menjadi kata dalam bahasa Indonesia, mis. 1.250.000 -> "satu juta dua ratus lima puluh ribu". */

function terbilang(int $n): string
{
    $s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($n < 12) return $s[$n];
    if ($n < 20) return terbilang($n - 10) . ' belas';
    if ($n < 100) return terbilang(intdiv($n, 10)) . ' puluh' . ($n % 10 ? ' ' . terbilang($n % 10) : '');
    if ($n < 200) return 'seratus' . ($n - 100 ? ' ' . terbilang($n - 100) : '');
    if ($n < 1000) return terbilang(intdiv($n, 100)) . ' ratus' . ($n % 100 ? ' ' . terbilang($n % 100) : '');
    if ($n < 2000) return 'seribu' . ($n - 1000 ? ' ' . terbilang($n - 1000) : '');
    if ($n < 1000000) return terbilang(intdiv($n, 1000)) . ' ribu' . ($n % 1000 ? ' ' . terbilang($n % 1000) : '');
    if ($n < 1000000000) return terbilang(intdiv($n, 1000000)) . ' juta' . ($n % 1000000 ? ' ' . terbilang($n % 1000000) : '');
    if ($n < 1000000000000) return terbilang(intdiv($n, 1000000000)) . ' miliar' . ($n % 1000000000 ? ' ' . terbilang($n % 1000000000) : '');
    return terbilang(intdiv($n, 1000000000000)) . ' triliun' . ($n % 1000000000000 ? ' ' . terbilang($n % 1000000000000) : '');
}

function terbilang_rupiah($amount): string
{
    $i = (int)round((float)$amount);
    return $i <= 0 ? 'Nol rupiah' : ucfirst(terbilang($i)) . ' rupiah';
}
