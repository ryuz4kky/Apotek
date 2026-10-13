# Apotek — Sistem Manajemen Apotek dan Kasir

Aplikasi web untuk membantu operasional apotek, mulai dari pengadaan dan penerimaan obat, pengelolaan stok per batch, pelayanan resep, hingga penjualan dan pencatatan keuangan. Aplikasi dikembangkan dengan menyesuaikan alur pengelolaan obat terhadap peraturan Badan Pengawas Obat dan Makanan (BPOM), dengan pembagian kewenangan petugas dan pencatatan tindakan agar proses dapat ditelusuri.

**Status:** versi awal untuk pengembangan dan pengujian lokal. Dukungan regulasi masih parsial; aplikasi belum siap untuk operasional apotek dengan data pasien riil. Seluruh data seed dan transaksi pengujian menggunakan data sintetis.

## Penyesuaian terhadap peraturan BPOM

Acuan pengembangan adalah **Peraturan BPOM Nomor 5 Tahun 2026 tentang Pengawasan Pengelolaan Obat dan Bahan Obat di Fasilitas Pelayanan Kefarmasian dan Fasilitas Lain**. Menurut [siaran resmi BPOM](https://www.pom.go.id/siaran-pers/regulasi-baru-bpom-atur-pengelolaan-obat-dan-bahan-obat-di-fasilitas-pelayanan-kefarmasian-dan-fasilitas-lain), peraturan ini menggantikan Peraturan BPOM Nomor 24 Tahun 2021 dan mengatur pengelolaan obat di apotek di bawah tanggung jawab apoteker penanggung jawab.

| Area pengelolaan | Dukungan yang tersedia pada aplikasi |
|---|---|
| Pengadaan dan penerimaan | Pesanan umum dengan persetujuan apoteker PJ, pemeriksaan data izin pemasok, faktur, checklist kemasan/dokumen/jumlah, serta verifikasi penerimaan sebelum stok tersedia |
| Penyimpanan dan ketertelusuran | Pencatatan lokasi, batch, tanggal kedaluwarsa, mutasi stok, suhu, dan tindakan koreksi atas penyimpangan suhu |
| Penyerahan obat | Persyaratan resep sesuai konfigurasi produk, tahap telaah dan pemeriksaan oleh apoteker, serta alokasi batch saat penjualan |
| Pengendalian stok | FEFO (*First Expired, First Out*), penolakan alokasi batch kedaluwarsa/ditandai ditarik, stok tidak layak jual, dan stok tidak cukup |
| Pembagian tanggung jawab | Izin akses berdasarkan peran, pemeriksaan masa kewenangan apoteker, dan riwayat tindakan petugas |
| Pengembalian | Penerimaan retur ke karantina, penilaian apoteker, dan pencatatan refund terpisah |

Tabel ini merangkum dukungan fitur, bukan bukti bahwa seluruh ketentuan telah dipenuhi atau bahwa aplikasi telah mendapat sertifikasi BPOM. Nomor izin edar masih berupa kolom pada schema dan belum lengkap pada formulir; validasi izin pemasok/petugas belum terhubung ke layanan verifikasi resmi. Proses penarikan, pemusnahan, dokumen khusus, dan pelaporan resmi belum lengkap. Transaksi produk pengawasan khusus masih diblokir.

Pemetaan aturan dan status implementasi tersedia di [Matriks Regulasi](docs/REGULASI.md) dan [Keterbatasan](docs/KETERBATASAN.md). Rentang suhu mengikuti konfigurasi lokasi dan harus disesuaikan dengan persyaratan produk; peringatan kedaluwarsa 90 hari merupakan pengaturan operasional awal.

## Fitur utama

- **Master data:** produk, barcode, golongan, satuan dan konversi per produk, harga awal, pemasok, lokasi, dan pengguna.
- **Persediaan:** penerimaan parsial/penuh, verifikasi apoteker, stok per batch dan lokasi, ledger mutasi, FEFO, serta opname dengan persetujuan koreksi.
- **Pelayanan resep:** pencatatan resep minimal, pencarian nomor/pasien/dokter, telaah, penyiapan, pemeriksaan akhir, dan riwayat petugas setiap tahap.
- **Kasir:** shift, pencarian produk/barcode, pilihan satuan, pembayaran tunai/kartu/transfer/QRIS manual, penyelesaian transaksi, dan struk salinan 80 mm.
- **Keuangan dan retur:** utang pemasok, pembayaran sebagian/penuh, pergerakan kas, penutupan shift, karantina barang retur, dan pencatatan refund.
- **Pengawasan:** catatan suhu, tindakan koreksi, audit tindakan, akses baca auditor, backup terenkripsi, dan pemeriksaan hasil restore.

Transfer, QRIS, dan refund merupakan pencatatan manual; aplikasi tidak melakukan transfer dana atau konfirmasi pembayaran dari bank secara otomatis.

## Teknologi

| Komponen | Teknologi |
|---|---|
| Backend | Laravel 13; kebutuhan PHP pada Composer `^8.3` |
| Frontend | Vue 3, Inertia 3, Tailwind CSS 4, Vite 8 |
| Database | PostgreSQL 17 |
| Lingkungan Docker | PHP 8.5, Composer 2, Node.js 24, Docker Compose |
| Antrean, sesi, dan cache | Database |
| Pengujian | PHPUnit |

## Jalankan dengan Docker

Docker Desktop harus aktif dan menyediakan Docker Compose. Jalankan perintah berikut dari direktori proyek; PHP, Composer, dan Node.js tersedia di container.

```sh
./docker/setup.sh
```

Buka [http://localhost:8088](http://localhost:8088). Skrip menyiapkan konfigurasi jika `.env` belum ada, memasang dependensi, membangun frontend, menjalankan migrasi dan seed, lalu menyalakan aplikasi, database, serta worker. Akun contoh memakai kata sandi dari `DEMO_PASSWORD` pada `.env`. File `LOGIN-LOKAL.txt`, bila tersedia di lingkungan lokal, diabaikan Git.

Jika `.env` sudah ada, pastikan kredensial database dan `DEMO_PASSWORD` sudah diisi sebelum menjalankan setup. Seed menyediakan data contoh untuk database awal dan dilewati jika produk sudah tersedia.

```sh
docker compose ps
docker compose logs -f app worker
docker compose stop
# Menyalakan kembali tanpa mereset data:
docker compose up -d
```

Port aplikasi hanya terikat ke 127.0.0.1:8088. Database berada di jaringan Compose dan volume `postgres-data`; tidak dipublikasikan ke host. `docker compose down` mempertahankan volume. Jangan menggunakan `down -v` bila data perlu dipertahankan.

## Peran dan akun contoh

| Peran | Email | Tugas utama |
|---|---|---|
| Admin | `admin@apotek.test` | Master produk, satuan, lokasi, pemasok, dan pengguna nonfarmasi |
| Gudang | `gudang@apotek.test` | Pesanan, pencatatan penerimaan, hitungan fisik, dan suhu |
| Apoteker PJ | `apoteker_pj@apotek.test` | Persetujuan pesanan, verifikasi, pelayanan resep, dan persetujuan koreksi stok |
| Apoteker | `apoteker@apotek.test` | Telaah/pemeriksaan resep, verifikasi penerimaan, dan penilaian retur |
| TTK | `ttk@apotek.test` | Penyiapan resep, pencatatan penerimaan, hitungan fisik, dan suhu |
| Kasir | `kasir@apotek.test` | Shift, penjualan, pembayaran, dan penerimaan retur transaksi sendiri |
| Pemilik | `pemilik@apotek.test` | Rekap, pembayaran pemasok, tinjauan shift, dan pencatatan refund |
| Auditor | `auditor@apotek.test` | Membaca dokumen, persediaan, keuangan, dan audit |

Izin petugas dan pemasok pada seed adalah contoh pengujian. Rincian pembagian kewenangan tersedia di [Audit Alur](docs/AUDIT-ALUR.md).

## Alur penggunaan

1. **Admin** (`admin@apotek.test`): tambah produk dan konversi per produk, lokasi, pemasok, pengguna nonfarmasi. Harga dasar/strip/dus dikonfigurasi terpisah.
2. **Gudang** (`gudang@apotek.test`): buat pesanan kebutuhan; setelah disetujui apoteker PJ, catat penerimaan sebagian/penuh dengan faktur, lokasi, batch, kedaluwarsa, biaya satuan beli, dan checklist. Belum menambah stok layak jual.
3. **Apoteker PJ** (`apoteker_pj@apotek.test`): setujui pesanan umum, verifikasi pemasok dan penerimaan melalui detail item dan checklist. Posting menambah stok dalam satuan dasar dan mencatat ledger. Dapat memulai serta menyetujui opname; hitungan fisik tidak langsung mengganti saldo.
4. **Kasir** (`kasir@apotek.test`): buka shift, cari produk/barcode, pilih satuan, catat pembayaran tunai/kartu/transfer/QRIS manual. Tunai/kartu tanpa resep langsung memposting penyerahan dengan FEFO atomik. Untuk transfer/QRIS manual, isi referensi dan konfirmasi dana sudah diterima di layar kasir yang sama.
5. **Resep**: apoteker mencatat nomor resep, pasien sintetis, dokter dan jumlah satuan dasar; pilih hasil pencarian untuk langsung menambahkan obat ke daftar resep, kemudian apoteker melakukan telaah → TTK/apoteker mencatat penyiapan → apoteker melakukan pemeriksaan akhir. Riwayat setiap tahap dan petugas disimpan terpisah. Di halaman kasir, gunakan **Cari / pilih resep** dan cari berdasarkan nomor resep, nama pasien, atau dokter. Pilih hasil pencarian untuk mengisi keranjang otomatis dengan sisa obat yang belum masuk pembayaran. Resep harus berstatus **Siap di kasir** terlebih dahulu. Sesudah pemeriksaan apoteker, kasir memilih resep dan menekan **Bayar & selesaikan**: pembayaran, alokasi batch FEFO, pengurangan stok, dan pencatatan penyerahan selesai atomik. Status pembayaran/penyerahan tetap disimpan terpisah untuk audit; tidak ada persetujuan ulang sesudah bayar.
6. **Pemilik** (`pemilik@apotek.test`): rekap pembayaran, bayar utang pemasok sebagian/penuh, setujui penutupan shift. Kasir asal menerima barang retur terkait transaksi yang sudah selesai ke lokasi berjenis karantina; apoteker menilai barang di menu Retur & refund, pemilik mencatat refund melalui bank dengan referensi.
7. **Suhu**: pengguna gudang/apoteker/TTK mencatat suhu lokasi yang rentangnya sudah dikonfigurasi. Penyimpangan wajib memiliki tindakan koreksi.
8. **Auditor** (`auditor@apotek.test`): membaca persediaan, pesanan, penjualan, shift, keuangan, retur, dan audit tanpa tombol perubahan. Nama pasien tidak ada pada laporan umum.

Nama pasien disimpan terenkripsi dengan APP_KEY. Rekam resep saat ini masih minimal; lihat keterbatasan sebelum penggunaan riil. Tidak ada mekanisme keputusan klinis otomatis.

## Pengujian

Database pengujian terpisah (`apotek_test`) dibuat oleh skrip:

```sh
./docker/test.sh
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=apotek_test -e BCRYPT_ROUNDS=4 app php docker/race-test.php
```

Penjagaan TestCase menolak tes bila environment/database tidak sesuai. Uji race mereset **hanya apotek_test** dan menjalankan dua proses PHP bersamaan. Hasil tercatat dalam `docs/PENGUJIAN.md`.

## Backup dan restore

```sh
docker compose exec -T app php artisan pharmacy:backup
# Atau melalui worker antrean:
docker compose exec -T app php artisan pharmacy:backup --queue
# Gunakan nama file hasil backup:
docker compose exec -T app php artisan pharmacy:restore-check storage/app/private/backups/NAMA.enc
```

Backup `pg_dump` terenkripsi dengan enkripsi Laravel yang terautentikasi. Simpan APP_KEY secara aman dan terpisah; kunci ini diperlukan untuk decrypt backup serta identitas pasien. `restore-check` memulihkan ke database sementara, membandingkan saldo dan ledger, lalu menghapus database sementara. Database aktif tidak ditimpa.

Untuk pemulihan operasional, gunakan lingkungan terisolasi dengan APP_KEY asal; decrypt backup ke file privat, `pg_restore --no-owner --no-acl` ke database baru, periksa saldo/ledger, lalu alihkan konfigurasi setelah validasi. Backup database belum mencakup berkas lampiran (fitur lampiran belum tersedia). Jadwal backup, penyimpanan eksternal, rotasi kunci, dan kebijakan retensi harus ditetapkan sebelum produksi.

## Environment

`.env.example` tidak berisi rahasia. `docker/setup.sh` membuat password database/akun contoh acak bila `.env` belum ada, serta membuat APP_KEY jika kosong.

| Variable | Kegunaan |
|---|---|
| APP_NAME, APP_ENV | Nama dan lingkungan aplikasi |
| APP_KEY | Enkripsi aplikasi/backup; jangan hilangkan atau commit |
| APP_DEBUG | false untuk menutup rincian exception |
| APP_URL | http://localhost:8088 |
| DB_CONNECTION | pgsql |
| DB_HOST, DB_PORT | db, 5432 di jaringan Docker |
| DB_DATABASE, DB_USERNAME, DB_PASSWORD | Database dan kredensial privat |
| SESSION_DRIVER, SESSION_LIFETIME | database, 60 menit |
| QUEUE_CONNECTION | database |
| CACHE_STORE | database |
| PHARMACY_TIMEZONE | Asia/Makassar secara default |
| DEMO_PASSWORD | Kata sandi akun seed, khusus pengembangan |

## Status implementasi

Fitur yang belum lengkap mencakup racikan, etiket dan konseling terstruktur, surat pesanan resmi/khusus, lampiran resep/dokumen, integrasi atau ekspor resmi SIPNAP, transfer lokasi, retur pemasok, pelepasan karantina, proses penarikan dan pemusnahan lengkap, serta laporan analitik/ekspor. Verifikasi regulasi menyeluruh dan kebijakan retensi juga belum selesai.

Dockerfile/Artisan serve saat ini untuk pengembangan; produksi membutuhkan server HTTP, HTTPS, penyediaan akun terverifikasi, serta pengamanan operasional. Rincian tersedia pada dokumentasi berikut:

- [Rancangan aplikasi](docs/RANCANGAN.md)
- [Matriks regulasi](docs/REGULASI.md)
- [Hasil pengujian](docs/PENGUJIAN.md)
- [Status dan keterbatasan](docs/KETERBATASAN.md)
- [Spesifikasi lengkap](docs/SPESIFIKASI.txt)
- [Pembagian tugas dan audit alur](docs/AUDIT-ALUR.md)

Pencarian pasien/dokter memakai indeks prefix kata yang di-HMAC, dengan nama pasien utama tetap terenkripsi. Ketik awal kata, misalnya `Siti` atau `dokter Rat`; pencarian nomor resep juga tersedia. Hasil dibatasi 15 resep terbaru yang cocok. Resep lama diindeks saat migration dijalankan. Akses hasil memerlukan izin `sales` dan hanya menampilkan identitas yang diperlukan untuk pembayaran, status, serta item; catatan klinis tidak dikirim. Respons tidak disimpan pada cache bersama.

Transaksi lama yang telah dibayar tetapi belum selesai tidak diubah otomatis. Sesudah resep diperiksa, kasir pemilik transaksi dapat membuka detail dan menekan **Selesaikan transaksi** tanpa mencatat pembayaran kedua. Persetujuan apoteker sebelumnya tetap tercatat pada resep/audit.

Pembagian tugas dan hasil audit terbaru: [docs/AUDIT-ALUR.md](docs/AUDIT-ALUR.md). Menu dan ringkasan mengikuti izin peran. Kasir melihat transaksi/shift sendiri; pemilik dan auditor dapat melihat rekap seluruh kasir. Untuk retur pada database lama, admin perlu membuat lokasi fisik berjenis `karantina`. Opname draft/pending yang dibuat sebelum pembaruan ini wajib dihitung ulang.
