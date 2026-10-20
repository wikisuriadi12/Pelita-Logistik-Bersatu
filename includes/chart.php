<?php
/** Grafik batang SVG: pendapatan dan biaya per bulan. Warna lewat variabel CSS. */

function chart_svg(array $labels, array $rev, array $cost): string
{
    $all = array_merge($rev, $cost);
    $mx = max(1, $all ? max($all) : 1);
    $step = pow(10, floor(log10($mx / 4)));
    $n = $mx / 4 / $step;
    $step *= $n <= 1 ? 1 : ($n <= 2 ? 2 : ($n <= 5 ? 5 : 10));
    $top = $step * ceil($mx / $step);

    $W = 600; $H = 240; $L = 54; $R = 10; $T = 12; $B = 28;
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    $count = max(1, count($labels));
    $g = $pw / $count;
    $bw = min(24, $g / 2 - 4);
    $y = function ($v) use ($T, $ph, $top) {
        return $T + $ph - $ph * $v / $top;
    };

    $s = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Pendapatan dan biaya per bulan">';
    for ($v = 0; $v <= $top + 1; $v += $step) {
        $yy = round($y($v), 1);
        $s .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '" class="grid"/>';
        $s .= '<text x="' . ($L - 6) . '" y="' . ($yy + 4) . '" text-anchor="end">' . number_format($v / 1e6, 1, ',', '.') . ' jt</text>';
    }
    foreach ($labels as $i => $label) {
        $cx = $L + $g * $i + $g / 2;
        $r = (float)($rev[$i] ?? 0);
        $c = (float)($cost[$i] ?? 0);
        $s .= '<rect class="bar-rev" x="' . round($cx - $bw - 1, 1) . '" y="' . round($y($r), 1) . '" width="' . round($bw, 1) . '" height="' . round($T + $ph - $y($r), 1) . '" rx="2"><title>' . e($label . ' pendapatan ' . rp($r)) . '</title></rect>';
        $s .= '<rect class="bar-cost" x="' . round($cx + 1, 1) . '" y="' . round($y($c), 1) . '" width="' . round($bw, 1) . '" height="' . round($T + $ph - $y($c), 1) . '" rx="2"><title>' . e($label . ' biaya ' . rp($c)) . '</title></rect>';
        $s .= '<text x="' . round($cx, 1) . '" y="' . ($H - 9) . '" text-anchor="middle">' . e($label) . '</text>';
    }
    return $s . '</svg>';
}
