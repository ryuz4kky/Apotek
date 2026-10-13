# Hasil pengujian — 12 Oktober 2026

Lingkungan: Docker, PHP 8.5, Laravel 13, PostgreSQL 17; database apotek_test terpisah. Data sintetis. Hasil akhir: 35 pengujian otomatis, 467 assertion, seluruhnya lulus; verifikasi ulang sesudah perubahan akhir juga lulus.

| Skenario | Hasil |
|---|---|
| 2 dus × 100 tablet | Stok pending 0; setelah verifikasi bertambah 200, posting ulang tidak menggandakan ledger |
| Penjualan 1 strip | Berkurang 10 satuan dasar |
| FEFO | Batch dengan kedaluwarsa lebih dekat dipilih |
| Karantina/kedaluwarsa/recalled | Ditolak; tidak ada pembayaran/penjualan yang tertinggal |
| Stok tidak cukup | Transaksi dan pembayaran rollback |
| Pembayaran dua kali | Satu penjualan, satu pembayaran, stok terpotong sekali |
| Kunci sama/payload berbeda | Ditolak |
| Resep belum diperiksa | Pembayaran ditolak tanpa membuat penjualan; sesudah tiga tahap apoteker, kasir langsung menyelesaikan pembayaran/stok/penyerahan |
| Refund/retur | Tidak menambah stok tersedia; membuat lapisan karantina, batas jumlah dan retry diperiksa |
| Opname | Saldo tetap sampai persetujuan; posting mutasi −5 dengan sumber dokumen |
| Penutupan shift | QRIS tidak masuk kas fisik; pembayaran manual dikonfirmasi kasir sebelum penyelesaian |
| Hak akses | Kasir gagal master; admin gagal persetujuan farmasi; apoteker kedaluwarsa ditolak |
| Login | Autentikasi berhasil; kegagalan dibatasi setelah 5 percobaan |
| Dua kasir bersamaan | Uji dua proses PHP sungguhan: satu SUCCESS, satu INSUFFICIENT, satu penjualan, saldo 0 |
| Backup/restore | Dump terenkripsi dipulihkan ke database sementara; saldo 619.0000 sama dengan jumlah ledger |
| Worker | Backup antrean selesai melalui App\Jobs\BackupDatabase |
| Browser | Login kasir, buka shift, jual 1 masker sintetis Rp2.000, tunai Rp5.000, kembalian Rp3.000, batch CONTOH-4 |
| Frontend | npm run build berhasil |
| Racikan tepat sekali | Belum diimplementasikan / belum diuji |

Bukti browser: `transaksi-contoh.jpg`. Uji browser meninggalkan satu shift dan satu transaksi sintetis pada database lokal untuk diperiksa. Tidak ada transfer dana riil.

Pengujian awal menemukan environment Docker menimpa variabel PHPUnit. Skrip final mengirim environment secara eksplisit; TestCase menolak database/environment yang tidak sesuai sebelum migrasi. Database pengembangan sintetis telah dibuat ulang sebelum uji browser. Pengujian printer fisik, beban, pemulihan seluruh operasi produksi, dan audit kepatuhan belum dilakukan.

## Perbaikan form resep

Penyimpanan resep melalui API diuji dengan nama pasien terenkripsi, jumlah obat, stok tidak berubah, dan penolakan nomor duplikat. Resep tanpa obat memberi pesan validasi Bahasa Indonesia. Uji browser memastikan form baru tidak membawa nomor sebelumnya, tanggal mengikuti zona waktu apotek, dan memilih hasil pencarian langsung menambahkan obat. Tidak ada resep tambahan yang disimpan saat uji form baru. Bukti: `form-resep-diperbaiki.jpg`.

## Pemilihan resep oleh kasir

Pencarian nomor resep serta prefix kata nama pasien/dokter diuji (termasuk beberapa kata, huruf besar/kecil, dan hasil kosong). Hak akses endpoint ditegakkan. Catatan klinis/harga modal tidak dikirim, nama pasien tetap terenkripsi dan indeks memakai HMAC. Sesudah pemeriksaan apoteker, kasir menerima pembayaran dan langsung memposting stok/penyerahan. Resep belum diperiksa ditolak sebelum pembayaran. Jumlah yang sudah dibayar mengurangi sisa resep; batas juga berlaku untuk obat bebas yang termasuk item resep. Persetujuan apoteker terdahulu tetap tercatat tanpa permintaan persetujuan ulang di kasir.

Uji browser akun kasir: pencarian `Pasien Uji` menampilkan nomor resep, pasien, dokter dan status; pemilihan resep otomatis memuat 9 kapsul yang belum dibayar (1 dari 10 telah masuk transaksi sebelumnya), subtotal Rp13.500. Pengujian ini tidak menyimpan pembayaran tambahan. Bukti: `pencarian-resep-kasir.jpg`.

## Penyelesaian kasir satu langkah

Alur terbaru menggantikan pembayaran menggantung: resep wajib siap sebelum checkout; transaksi tunai/kartu selesai langsung, dan transfer/QRIS manual selesai setelah kasir mencatat referensi serta mengonfirmasi dana diterima. Uji membuktikan klik ulang tidak memotong stok dua kali, status checked tanpa identitas pemeriksa tidak cukup, stok kurang me-rollback pembayaran, dan transaksi lama dapat diselesaikan oleh kasir asal setelah persetujuan apoteker. Tidak ada perubahan otomatis pada resep/transaksi pengguna yang sudah ada.

Uji browser akhir memakai server QA sementara dengan database apotek_test dan cookie sesi terpisah. Resep sintetis siap → kasir memilih → tunai Rp20.000 untuk total Rp15.000 → Bayar & selesaikan → pembayaran lunas, penyerahan diposting, batch FEFO teralokasi 10 kapsul, kembalian Rp5.000. Tidak ada persetujuan ulang setelah bayar. Fixture: `docker/checkout-smoke.php`. Container QA sudah dihentikan; database aplikasi pengguna tidak berubah. Bukti: `kasir-resep-langsung-selesai.jpg`.

## Audit alur berdasarkan tugas

Hasil terbaru: **33 pengujian fitur, 437 assertion, seluruhnya lulus**. Build Vue/Vite berhasil. Pemeriksaan Docker menunjukkan app/database sehat dan worker berjalan.

Tambahan pengujian: matriks halaman/tindakan peran; TTK hanya menyiapkan sesudah telaah; retry resep tidak melompati status; PO draft/approval/penerimaan parsial/penuh dan penolakan kelebihan penerimaan; checklist sebelum posting dan pemasok kedaluwarsa; opname mendeteksi mutasi bersaldo bersih nol dan menyediakan hitung ulang; pembayaran pemasok parsial/penuh/idempotensi; kas masuk/keluar, penutupan dan tinjauan shift; pembayaran belum terkonfirmasi menghalangi penutupan; data kasir dibatasi pada transaksi/shift sendiri; pencarian tiap menu. Retur diuji sebagai penerimaan barang terpisah dari refund, penilaian barang tidak layak tetap tidak menambah saldo tersedia, refund tidak dapat diduplikasi.

Browser memakai database pengujian/cookie terpisah: gudang membuat pesanan 2 dus Paracetamol, detail menampilkan 200 tablet, apoteker PJ menyetujui, dan menu kasir hanya menampilkan tugas kasir. Bukti `pesanan-gudang-uji.jpg` dan `pesanan-disetujui-uji.jpg`. Container sementara dihentikan. Tidak semua langkah setiap peran diulang melalui browser. Database aplikasi pengguna tidak direset untuk pengujian ini; migration menambahkan tabel/kolom dan menandai opname lama untuk hitung ulang.

Panduan pembagian tugas: [AUDIT-ALUR.md](AUDIT-ALUR.md). Keterbatasan fitur lengkap tetap tercatat pada KETERBATASAN.md.

## Visibilitas akses

Hasil akhir setelah pembaruan tampilan: **35 pengujian fitur, 467 assertion lulus**; build Vue/Vite berhasil. Daftar navigasi dari backend diuji untuk kasir/auditor; kredensial apoteker kedaluwarsa tidak mengirim izin tindakan klinis. Browser auditor memperlihatkan utang pemasok tanpa tombol bayar maupun kolom tindakan, serta tanpa menu kasir/pengguna. Bukti: `auditor-tanpa-tombol-transaksi.jpg`. Server QA terpisah sudah dihentikan.
