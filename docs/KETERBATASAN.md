# Status dan keterbatasan

Versi awal ini menjalankan backend dan database sungguhan. Ruang lingkup lampiran yang lengkap belum selesai.

## Sudah tersedia dan diuji

Login/sesi, pembatasan percobaan login, izin backend berdasarkan peran, master produk/satuan dan konversi, pemasok/lokasi, penerimaan dan verifikasi, batch terpisah dari lapisan biaya, FEFO, ledger, blok stok tidak layak/negatif, idempotensi kasir, pembayaran manual, alur resep minimal dengan persetujuan, detail/struk salinan 80 mm, shift, refund/retur ke karantina, opname dan koreksi dengan persetujuan, suhu dan penyimpangan, utang pemasok, audit, backup terenkripsi dan restore ke database sementara.

## Belum selesai sesuai lampiran

- Konfigurasi data apotek/perizinan, pengaturan timezone melalui UI, pengelolaan dokumen kredensial, izin tambahan melalui UI, opsi 2FA, perubahan harga dan riwayatnya, deaktivasi/master edit.
- Pesanan umum, persetujuan, dan penerimaan parsial sudah berfungsi. Format surat pesanan resmi/khusus, lampiran dokumen dan pembatalan pesanan belum tersedia. Biaya perolehan belum memasukkan pajak/ongkir/diskon pembelian.
- Reservasi hold/resume, pajak dan pembulatan POS, pembayaran gabungan pada UI (backend mendukung beberapa metode), piutang, struk 58 mm, pembatalan dokumen pembalik. Transaksi kasir baru langsung mengalokasikan stok saat selesai; reservasi hold/resume belum tersedia. Transaksi lama yang sudah dibayar/belum selesai perlu dituntaskan setelah resep siap.
- Dokumen resep privat, tanggal lahir/alergi, dosis/aturan pakai per item, formulir telaah administratif/farmasetik/klinis terstruktur, klarifikasi dokter, konseling, etiket/salinan resep. Identitas pasien/dokter ditampilkan pada pencarian resep kasir sesuai kebutuhan pembayaran; status resep setelah penyerahan parsial belum lengkap.
- Racikan dan pencatatan pemakaian bahan. Skenario pengurangan tepat sekali untuk racikan belum diimplementasikan atau diuji.
- Aturan khusus narkotika/psikotropika/prekursor/OOT dan dokumennya. Transaksi khusus diblok. Tidak ada integrasi/ekspor format resmi SIPNAP.
- Transfer lokasi, retur pemasok, pelepasan karantina, penarikan beserta dokumennya, pemusnahan dan berita acara. Blok recalled sudah ada pada alokasi, namun proses recall belum memiliki UI/backend penerbitan dokumen.
- Lampiran, pemeriksaan MIME/ukuran dan jejak akses dokumen; jadwal/pengingat suhu, pembatasan transaksi selama opname dan hitung ulang bertahap. Hitung ulang dasar sudah tersedia; persetujuan menolak snapshot bila terdapat mutasi baru, termasuk ketika saldo bersih kembali sama.
- Retur sudah dipisahkan: kasir asal menerima barang ke area karantina, apoteker menilai kondisi, pemilik mencatat refund melalui bank dengan referensi. Pelepasan kembali menjadi layak jual dan unggah bukti bank belum tersedia. Tombol refund mencatat dana, tidak melakukan transfer bank riil. Retur lintas kasir belum tersedia.
- Laporan analitik berfilter/ekspor via antrean, penilaian inventaris formal, beban, jurnal, rekonsiliasi, laporan laba rugi bersih. Audit saat ini mencatat tindakan/dokumen dan sebagian nilai penting, belum snapshot before/after lengkap.
- Retensi/penghapusan/arsip berdasarkan aturan terverifikasi, jadwal dan penyimpanan backup eksternal, backup lampiran, prosedur disaster recovery produksi, hardening server, pemisahan user database dengan hak terbatas.
- Verifikasi hukum menyeluruh, pengujian perangkat printer/barcode fisik, dan uji beban belum selesai.

Jangan memakai seed sebagai izin pemasok/kewenangan apoteker nyata. Nilai suhu dan 90 hari peringatan adalah contoh operasional, bukan batas regulasi yang berlaku untuk semua produk. Sistem tidak menyatakan otomatis menjamin kepatuhan.
