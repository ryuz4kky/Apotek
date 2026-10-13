# Audit alur berdasarkan tugas — 12 Oktober 2026

Perubahan telah diterapkan pada aplikasi Docker di localhost:8088. Uji otomatis memakai database `apotek_test`; pemeriksaan browser terbaru memakai server sementara localhost:8089 dan cookie terpisah. Pesanan uji di browser tidak dibuat pada database aplikasi pengguna. Server sementara sudah dihentikan.

## Tugas setiap akun

| Akun | Tugas operasional |
|---|---|
| admin@apotek.test | Produk/satuan/harga awal, lokasi, pemasok, akun nonfarmasi. Tidak memberi persetujuan farmasi atau mencatat pembayaran. |
| gudang@apotek.test | Membuat pesanan kebutuhan, mencatat penerimaan/faktur/batch, hasil pemeriksaan barang, hitungan fisik, suhu dan pemasok. |
| ttk@apotek.test | Penyiapan resep sesudah telaah, pencatatan penerimaan, hitungan fisik dan suhu. Tidak melakukan telaah/pemeriksaan akhir atau persetujuan stok. |
| apoteker_pj@apotek.test | Persetujuan pesanan, verifikasi pemasok/penerimaan, telaah dan pemeriksaan resep, koreksi opname, penilaian barang retur. Kewenangan harus aktif. |
| apoteker@apotek.test | Telaah/pemeriksaan resep, verifikasi penerimaan, penilaian retur. Persetujuan pesanan/koreksi opname hanya diberikan kepada PJ secara default. |
| kasir@apotek.test | Shift sendiri, transaksi sendiri, pembayaran dan penyelesaian penjualan, kas masuk/keluar, penerimaan retur dari transaksi sendiri. |
| pemilik@apotek.test | Rekap seluruh kasir, pembayaran utang pemasok, tinjauan penutupan shift, pencatatan refund melalui bank. |
| auditor@apotek.test | Membaca dokumen operasional, persediaan, keuangan dan audit. Tidak mengubah transaksi, resep atau persetujuan. |

Menu, ringkasan dan tombol mengikuti izin. Backend menolak tindakan tanpa izin meskipun URL atau payload dikirim langsung. Harga modal disembunyikan dari akun tanpa izin `cost`. Dashboard gudang/TTK/apoteker tidak mengirim daftar penjualan atau metrik uang yang tidak diperlukan. Kasir tidak mendapat halaman penerimaan, stok, keuangan pemasok, pengguna atau rekam klinis penuh; pencarian resep kasir tetap tersedia untuk pembayaran.

## Perbaikan proses

### Pembelian dan penerimaan

Gudang membuat pesanan dengan pilihan produk dan satuan → apoteker PJ memeriksa detail dan menyetujui → gudang/TTK mencatat penerimaan sebagian/penuh → petugas mencatat hasil pemeriksaan → apoteker memverifikasi dan memposting stok. Penerimaan pending tidak menambah saldo layak jual. Jumlah yang sudah dipesan tidak boleh dilampaui, termasuk penerimaan lain yang masih pending.

Detail memperlihatkan produk, jumlah, konversi satuan dasar, batch, tanggal kedaluwarsa dan dokumen pemasok. Pemasok tidak dapat diverifikasi jika identitas dokumen/masa berlaku tidak lengkap. Checklist awal kosong; hasil dapat diperbaiki dengan alasan sebelum posting, dan tidak dapat ditimpa sesudah posting. Pembayaran pemasok dilakukan pemilik secara terpisah, dapat sebagian/penuh, tidak melebihi utang, dan retry tidak membuat pembayaran kedua.

### Pelayanan resep dan kasir

Apoteker membuat/telaah resep → TTK atau apoteker mencatat penyiapan → apoteker melakukan pemeriksaan akhir → kasir mencari pasien/dokter/nomor, memilih resep siap dan menyelesaikan pembayaran. Detail resep menampilkan item serta riwayat tahap, catatan dan petugas. Permintaan dengan status lama ditolak agar klik ulang tidak melompati tahapan. Kasir tidak diminta memberi persetujuan klinis ulang setelah pembayaran.

### Opname

Gudang/TTK/apoteker memulai hitungan pada lapisan stok yang sudah diposting → pembuat opname mencatat jumlah/alasan → PJ menyetujui koreksi ledger. Tidak boleh ada dua opname aktif pada lapisan sama. Persetujuan membandingkan saldo dan nomor mutasi terakhir; transaksi keluar/masuk yang mengembalikan saldo awal tetap memerlukan hitung ulang. Tombol hitung ulang memperbarui snapshot dan mengosongkan hasil lama.

Opname draft/pending dari sebelum pembaruan wajib dihitung ulang, karena versi mutasi pada waktu snapshot lama tidak dapat dipastikan.

### Shift dan kas

Kasir membuka shift sendiri, mencatat pergerakan kas dengan alasan, menghitung kas saat penutupan; pemilik meninjau shift yang sudah ditutup. QRIS/transfer tidak menambah kas laci. Shift dengan pembayaran lama belum terkonfirmasi tidak dapat ditutup. Hasil penutupan tidak dapat ditimpa; kas masuk/keluar baru ditolak setelah penutupan. Pergerakan kas memakai kunci retry agar tidak tercatat dua kali.

### Retur dan refund

Kasir asal membuka detail transaksi selesai → menerima jumlah barang ke lokasi **karantina** dengan kondisi/alasan → apoteker mencatat tetap karantina atau tidak layak → pemilik mencatat refund melalui bank dengan referensi. Penerimaan barang dan pembayaran dana mempunyai catatan terpisah. Refund tidak menambah stok kembali, dan penerimaan barang tidak otomatis mengeluarkan dana. Tidak ada transfer bank otomatis.

Jumlah kumulatif retur tidak melebihi jumlah terjual, batch asal tetap terlacak, stok retur tidak masuk saldo layak jual, dan retry penerimaan/refund tidak menggandakan stok atau dana. Penilaian farmasi tidak otomatis melepaskan karantina.

Database pengguna lama belum mempunyai lokasi karantina. Admin perlu menambahkan lokasi berjenis `karantina` sesuai area fisik yang digunakan sebelum kasir menerima retur. Seed baru menyediakan lokasi contoh hanya untuk pengujian.

### Pencarian

Kolom pencarian sekarang sesuai menu: nomor dokumen/pemasok, produk/batch, nama kasir, pengguna, tindakan audit, atau nama lokasi. Daftar resep memakai pencarian nomor/nama pasien/dokter yang sama dengan kasir; identitas pasien utama tetap terenkripsi. Parameter pencarian dipertahankan saat pindah halaman.

## Bukti dan batas pemeriksaan

Pengujian fitur mencakup akses peran, persetujuan/urutan tahap, penerimaan parsial, batas pesanan, konversi stok, checklist, supplier kedaluwarsa, snapshot opname, retry pembayaran, retur/penilaian/refund, pembatasan data kasir, pencarian menu, shift, serta regresi kasir/resep sebelumnya. Hasil akhir dicatat di PENGUJIAN.md.

Browser: gudang membuat pesanan dua dus Paracetamol → detail 200 tablet → apoteker PJ menyetujui; menu kasir hanya menampilkan tugas kasir. Bukti `pesanan-gudang-uji.jpg` dan `pesanan-disetujui-uji.jpg`. Alur lain diperiksa melalui pengujian backend; belum semua langkah setiap akun diulang melalui browser.

Racikan, etiket/konseling terstruktur, surat pesanan resmi/khusus, transfer lokasi, retur pemasok, pelepasan karantina, recall/pemusnahan lengkap, laporan analitik/ekspor dan seluruh persyaratan lampiran belum selesai. Rincian ada di KETERBATASAN.md. Audit ini memperbaiki alur yang tersedia dan tidak menyatakan keseluruhan spesifikasi telah selesai.

## Tampilan sesuai izin

Navigasi sekarang menerima daftar modul dari backend berdasarkan izin akun, sehingga menu tidak memiliki aturan akses terpisah yang dapat berbeda dari server. Tombol memakai izin yang efektif; kewenangan farmasi kedaluwarsa menyembunyikan tindakan klinis dan penyiapan apoteker. Akses baca yang masih sah tetap terlihat. Kolom tindakan kosong, kolom penerimaan retur pada akun pembaca, serta kontrol checklist yang tidak dapat diedit disembunyikan; hasil checklist ditampilkan sebagai teks. Penyelesaian penjualan tanpa resep hanya ditampilkan untuk kasir asal yang berizin.

Browser auditor di database pengujian membuktikan halaman utang pemasok tidak mempunyai tombol bayar/kolom tindakan, menu kasir dan menu pengguna tidak ditampilkan. Bukti: `auditor-tanpa-tombol-transaksi.jpg`. Backend tetap menolak akses tanpa izin.
