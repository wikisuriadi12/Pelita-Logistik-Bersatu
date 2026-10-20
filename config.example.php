<?php
/**
 * Salin file ini menjadi config.php lalu isi sesuai server Anda.
 * config.php tidak ikut ke GitHub (lihat .gitignore).
 */
return [
    // Database MySQL / MariaDB (buat dulu databasenya di cPanel atau phpMyAdmin)
    'db_host'     => 'localhost',
    'db_name'     => 'pelita_tms',
    'db_user'     => 'root',
    'db_pass'     => '',
    'db_timezone' => '+07:00',

    'timezone'    => 'Asia/Jakarta',

    // Identitas yang tampil di invoice
    'company_name'    => 'Pelita Logistik Bersatu',
    'company_address' => '',
    'company_phone'   => '',
    'bank_info'       => '',   // contoh: Bank BCA 000-000-0000 a.n. Pelita Logistik Bersatu

    // PPN atau pajak lain dalam persen. Isi 0 bila tidak dipungut.
    'default_tax_rate' => 0,

    // Folder bukti pengiriman. Sebaiknya di luar folder publik bila hosting mengizinkan.
    'upload_dir'   => __DIR__ . '/storage/pod',
    'max_upload_mb' => 5,
];
