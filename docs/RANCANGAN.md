# Rancangan transaksi

Satu apotek, beberapa lokasi. Waktu database UTC, tampilan Asia/Makassar secara default.

Produk → satuan produk (konversi decimal per produk) → batch fisik → lapisan biaya penerimaan di lokasi. Ledger append-only adalah sumber mutasi; saldo lapisan merupakan proyeksi yang diperbarui dalam transaksi yang sama. Baris produk dikunci sebelum alokasi FEFO agar semua kasir menggunakan urutan kunci yang sama. Saldo tidak boleh negatif.

Pesanan → persetujuan → penerimaan pending → verifikasi → posting ledger + utang. Penerimaan parsial tidak mengubah jumlah pesanan dan tidak otomatis membayar pemasok.

Resep → telaah → penyiapan → pemeriksaan → penyerahan. Kewenangan farmasi terpisah dari admin. Status pembayaran terpisah dari penyerahan. Produk pengawasan khusus diblok sampai konfigurasi aturan diverifikasi oleh apoteker.

Resep ditelaah/disiapkan/diperiksa terlebih dahulu → siap di kasir → pembayaran dan penyelesaian FEFO atomik oleh kasir. Transaksi tanpa resep juga langsung selesai setelah pembayaran. Klik ulang menggunakan kunci unik, payload berbeda dengan kunci sama ditolak. Refund terpisah dari penilaian retur; barang kembali masuk karantina.

Opname snapshot → hitungan → persetujuan → koreksi ledger, dengan pemeriksaan nomor mutasi terakhir sejak snapshot. Saldo yang kembali sama setelah mutasi tetap memerlukan hitung ulang.

## Matriks izin awal

| Peran | Master | Gudang/pengadaan | Farmasi | Kasir/shift | Keuangan | Audit |
|---|---|---|---|---|---|---|
| Pemilik | baca | baca | — | laporan | penuh | baca |
| Administrator | penuh/pengguna | baca | — | — | — | baca |
| Apoteker penanggung jawab | baca | setujui pesanan/verifikasi/koreksi | telaah/persiapan/pemeriksaan/penilaian retur | penyerahan | — | baca |
| Apoteker pendamping | baca | verifikasi | telaah/penyerahan | penyerahan | — | baca |
| TTK | baca | penerimaan | persiapan | — | — | — |
| Pembelian/gudang | baca/pemasok | pesanan/penerimaan/opname | — | — | — | — |
| Kasir | baca tanpa modal | — | — | penuh tanpa refund/diskon | — | — |
| Auditor | baca | baca | — | baca | baca | baca |

Izin tambahan: harga modal, harga jual, diskon, refund, koreksi stok, ekspor pasien, pengawasan khusus. Tidak diberikan otomatis kepada administrator teknis.
