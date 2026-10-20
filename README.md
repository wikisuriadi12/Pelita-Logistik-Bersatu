# Pelita TMS

Transportation Management System untuk **Pelita Logistik Bersatu**.
Dibuat atas nama Hendra Gunawan dan Wiki Suriadi.

Aplikasi web PHP + MySQL tanpa framework, ringan, dan bisa dipasang di hosting cPanel (mis. IDwebhost) maupun server lokal.

## Fitur

- **Order pengiriman**: buat order, pilih armada dan driver, tandai berangkat, selesaikan. Nomor order otomatis (`PLB-YYMM-0001`).
- **Pengecekan saat penjadwalan**: kapasitas armada, KIR dan SIM yang habis, serta ketersediaan armada dan driver. Dua dispatcher tidak bisa memakai armada yang sama.
- **Biaya perjalanan**: BBM, tol, uang jalan, lain-lain per order. Margin dihitung otomatis.
- **Bukti pengiriman (POD)**: unggah foto JPG/PNG atau PDF. Order tidak bisa ditandai terkirim tanpa bukti dan nama penerima.
- **Invoice dan penagihan**: invoice dari order yang sudah terkirim, pajak opsional, pembayaran bertahap, status lunas otomatis, cetak atau simpan PDF dari browser.
- **Cetak invoice**: halaman A4 dengan kop perusahaan, rincian order, terbilang, rekening pembayaran, kolom tanda tangan, dan cap LUNAS atau BATAL. Dari detail invoice klik **Cetak invoice**, lalu simpan sebagai PDF bila perlu.
- **Master vendor**: subkontraktor, bengkel, SPBU, tol, asuransi, dan lainnya, lengkap dengan kontak, NPWP, rekening bank, dan tempo pembayaran. Vendor bisa dipilih saat mencatat biaya order, dan laporan menampilkan biaya per vendor.
- **Laporan**: ringkasan bulanan, per pelanggan, per armada, biaya per kategori, umur piutang. Ekspor ke Excel (CSV) dan cetak PDF.
- **Login dan peran**: Admin, Dispatcher, Keuangan, Manajemen, Driver.
- **Peringatan di ringkasan**: order terlambat, KIR dan SIM hampir habis, order belum ditagihkan, invoice jatuh tempo.

## Peran

| Peran | Akses |
|---|---|
| Admin | Semua, termasuk kelola pengguna |
| Dispatcher | Order, pelanggan, vendor, armada, driver, jadwal, bukti pengiriman |
| Keuangan | Pelanggan, vendor, invoice, pembayaran, biaya, laporan |
| Manajemen | Lihat ringkasan, order, vendor, armada, driver, invoice, laporan |
| Driver | Hanya order miliknya: berangkat, biaya, unggah bukti, selesai |

Akun Driver dihubungkan ke data driver di menu **Pengguna**.

## Kebutuhan

- PHP 7.4 atau lebih baru (diuji dengan PHP 8.3) dengan ekstensi `pdo_mysql`, `fileinfo`, `mbstring`
- MySQL 5.7+ atau MariaDB 10.3+

## Pemasangan di hosting (cPanel / IDwebhost)

1. Buat database dan pengguna database di **MySQL Databases**, beri semua hak akses.
2. Unggah seluruh isi repo ke folder domain (mis. `public_html`). Bisa lewat File Manager, Git Version Control, atau FTP.
3. Salin `config.example.php` menjadi `config.php`, lalu isi `db_name`, `db_user`, `db_pass`, dan identitas perusahaan.
4. Buka `https://domain-anda/install.php`, buat akun admin pertama. Tabel dibuat otomatis.
5. **Hapus `install.php`** dari server.
6. Pastikan folder `storage/pod` bisa ditulis oleh PHP (izin 750 atau 755).

### Memperbarui sistem yang sudah terpasang

Setelah menarik versi terbaru, jalankan file di `database/migrations/` yang belum pernah Anda jalankan, satu kali, lewat phpMyAdmin. Fitur vendor memakai `002_vendors.sql`. Pemasangan baru tidak perlu migrasi.

Untuk invoice cetak, isi `company_npwp`, `invoice_signer`, dan `invoice_signer_title` di `config.php`.

## Pemasangan di server lokal (XAMPP, Laragon, atau PHP bawaan)

```bash
git clone https://github.com/wikisuriadi12/Pelita-Logistik-Bersatu.git
cd Pelita-Logistik-Bersatu
cp config.example.php config.php   # isi koneksi database lokal
php -S 0.0.0.0:8080                # lalu buka http://localhost:8080/install.php
```

Untuk dipakai dari komputer lain di jaringan kantor, jalankan lewat Apache/Nginx dan pakai HTTPS bila diakses dari internet.

## Struktur

```
index.php            Ringkasan dan peringatan
orders.php           Daftar order
order_form.php       Buat atau ubah order
order_view.php       Jadwal, status, biaya, bukti pengiriman, riwayat
pod.php              Menyajikan file bukti, hanya untuk yang berhak
customers.php        Pelanggan
vehicles.php         Armada
drivers.php          Driver
invoices.php         Daftar dan pembuatan invoice
invoice_view.php     Detail dan pembayaran
invoice_print.php    Cetak invoice A4
vendors.php          Master vendor
reports.php          Laporan dan ekspor
users.php            Pengguna dan peran (admin)
login.php, logout.php, install.php
includes/            Inti aplikasi (bootstrap, header, footer, upload, grafik)
database/schema.sql  Skema database
storage/pod/         File bukti pengiriman (tidak bisa diakses langsung)
```

## Keamanan

- Kata sandi memakai `password_hash`. Login dibatasi 5 kali gagal per 15 menit per IP dan nama pengguna.
- Semua formulir memakai token CSRF. Semua query memakai prepared statement. Semua keluaran di-escape.
- File bukti dicek tipe sebenarnya (bukan hanya ekstensi), diberi nama acak, disimpan di `storage/`, dan hanya bisa dibuka lewat `pod.php` setelah login.
- Ekspor CSV menetralkan sel yang diawali `=`, `+`, `-`, `@`.
- Setiap perubahan penting tercatat di tabel `audit_log` dan tampil sebagai riwayat di halaman order.
- `config.php`, folder `includes`, `database`, dan `storage` ditutup lewat `.htaccess` (Apache). Pada Nginx, tutup folder tersebut di konfigurasi server.

## Catatan

- File CSV memakai pemisah titik koma dan BOM UTF-8 supaya langsung terbaca benar di Excel berlokal Indonesia.
- PDF dibuat lewat tombol **Cetak / simpan PDF** di browser.
- PPN: isi persentase di config (`default_tax_rate`) atau per invoice. Atur sesuai ketentuan pajak yang berlaku untuk usaha Anda.

## Status pengujian

Semua file lolos pengecekan sintaks PHP 8.3, dan halaman login serta pemasangan sudah dicoba berjalan. Alur lengkap dengan database (order sampai invoice) belum dijalankan pada MySQL sungguhan, jadi lakukan uji coba dengan data contoh sebelum dipakai operasional.
