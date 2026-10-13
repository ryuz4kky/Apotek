<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Inventory
{
    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['transaction' => $message]);
    }

    public static function audit(User $u, string $action, string $type, ?int $id, array $data = []): void
    {
        DB::table('audit_logs')->insert(['user_id' => $u->id, 'action' => $action, 'source_type' => $type, 'source_id' => $id, 'data' => json_encode($data), 'created_at' => now()]);
    }

    public static function move(int $layer, string $delta, string $kind, string $type, int $source, User $u, string $reason): void
    {
        $row = DB::table('inventory_layers')->where('id', $layer)->lockForUpdate()->first();
        $next = bcadd($row->quantity, $delta, 4);
        if (bccomp($next, '0', 4) < 0) {
            self::fail('Stok tidak cukup.');
        }
        DB::table('inventory_layers')->where('id', $layer)->update(['quantity' => $next, 'updated_at' => now()]);
        DB::table('stock_ledger')->insert(['layer_id' => $layer, 'delta' => $delta, 'balance_after' => $next, 'kind' => $kind, 'source_type' => $type, 'source_id' => $source, 'user_id' => $u->id, 'reason' => $reason, 'created_at' => now()]);
    }

    public static function eligible(int $product)
    {
        return DB::table('inventory_layers as l')->join('batches as b', 'b.id', '=', 'l.batch_id')->where('b.product_id', $product)->where('b.recalled', false)->where('b.expires_at', '>', now()->timezone(config('pharmacy.timezone'))->toDateString())->where('l.status', 'available')->where('l.quantity', '>', 0);
    }

    public static function allocate(int $product, string $qty, string $type, int $source, User $u, ?int $saleItem = null): string
    {
        DB::table('products')->where('id', $product)->lockForUpdate()->first();
        $layers = self::eligible($product)->select('l.*', 'b.expires_at')->orderBy('b.expires_at')->orderBy('l.id')->lockForUpdate()->get();
        $remaining = $qty;
        $cost = '0';
        foreach ($layers as $layer) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }
            $take = bccomp($remaining, $layer->quantity, 4) > 0 ? $layer->quantity : $remaining;
            $c = bcmul($take, $layer->cost_per_base, 2);
            self::move($layer->id, bcsub('0', $take, 4), $type, $type, $source, $u, 'Alokasi FEFO');
            if ($saleItem) {
                DB::table('sale_allocations')->insert(['sale_item_id' => $saleItem, 'layer_id' => $layer->id, 'quantity' => $take, 'cost' => $c]);
            }
            $remaining = bcsub($remaining, $take, 4);
            $cost = bcadd($cost, $c, 2);
        }
        if (bccomp($remaining, '0', 4) > 0) {
            self::fail('Stok layak jual tidak cukup untuk produk #'.$product.'.');
        }

        return $cost;
    }

    public static function verifyReceipt(int $id, User $u): void
    {
        Access::check($u, 'receive.verify');
        DB::transaction(function () use ($id, $u) {
            $r = DB::table('receipts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($r, 404);
            if ($r->status === 'posted') {
                return;
            }
            $order = $r->purchase_order_id ? DB::table('purchase_orders')->where('id', $r->purchase_order_id)->lockForUpdate()->first() : null;
            $supplier = DB::table('suppliers')->find($r->supplier_id);
            if (! $supplier->verified || ! $supplier->license_expires || $supplier->license_expires < now()->timezone(config('pharmacy.timezone'))->toDateString()) {
                self::fail('Pemasok harus terverifikasi dan dokumen masih berlaku.');
            }
            $check = json_decode($r->checklist, true);
            foreach (['packaging', 'documents', 'quantity'] as $key) {
                if (empty($check[$key])) {
                    self::fail('Checklist kemasan, dokumen, dan jumlah harus lengkap.');
                }
            }
            $layers = DB::table('inventory_layers')->where('receipt_id', $id)->orderBy('id')->get();
            $ids = DB::table('batches')->whereIn('id', $layers->pluck('batch_id'))->orderBy('product_id')->pluck('product_id')->unique();
            DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($layers as $l) {
                $b = DB::table('batches')->find($l->batch_id);
                if ($b->expires_at <= now()->timezone(config('pharmacy.timezone'))->toDateString() || $b->recalled) {
                    self::fail('Batch kedaluwarsa atau ditarik tidak dapat diposting sebagai stok tersedia.');
                }
                $p = DB::table('products')->find($b->product_id);
                if ($p->special_control) {
                    self::fail('Pengadaan pengawasan khusus belum diaktifkan: perlu verifikasi aturan dokumen.');
                }
                DB::table('inventory_layers')->where('id', $l->id)->update(['status' => 'available']);
                self::move($l->id, $l->received_quantity, 'receipt', 'receipt', $id, $u, 'Penerimaan diverifikasi');
            }
            DB::table('receipts')->where('id', $id)->update(['status' => 'posted', 'verified_by' => $u->id, 'updated_at' => now()]);
            if ($order) {
                $complete = true;
                foreach (DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->get() as $oi) {
                    $posted = DB::table('receipt_items as i')->join('receipts as r', 'r.id', '=', 'i.receipt_id')->where('r.purchase_order_id', $order->id)->where('r.status', 'posted')->where('i.product_id', $oi->product_id)->sum('i.quantity_base');
                    if (bccomp((string) $posted, $oi->quantity_base, 4) < 0) {
                        $complete = false;
                    }
                }
                DB::table('purchase_orders')->where('id', $order->id)->update(['status' => $complete ? 'received' : 'partial', 'updated_at' => now()]);
            }
            self::audit($u, 'verify', 'receipt', $id);
        }, 3);
    }

    public static function prescriptionReady(object $rx): bool
    {
        if ($rx->status !== 'checked' || ! $rx->reviewed_by || ! $rx->checked_by) {
            return false;
        }
        $ids = array_unique([$rx->reviewed_by, $rx->checked_by]);
        $approvers = User::whereIn('id', $ids)->get();
        if ($approvers->count() !== count($ids)) {
            return false;
        }
        foreach ($approvers as $approver) {
            if (! $approver->active || ! in_array($approver->role, ['apoteker_pj', 'apoteker']) || ! $approver->credential_expires || $approver->credential_expires < now()->timezone(config('pharmacy.timezone'))->toDateString()) {
                return false;
            }
        }

        return true;
    }

    public static function requirePrescriptionReady(object $rx): void
    {
        if (! self::prescriptionReady($rx)) {
            self::fail('Resep belum siap di kasir. Apoteker harus menyelesaikan telaah, penyiapan, dan pemeriksaan terlebih dahulu.');
        }
    }

    public static function checkout(array $d, User $u): int
    {
        Access::check($u, 'sales');

        return DB::transaction(function () use ($d, $u) {
            DB::table('users')->where('id', $u->id)->lockForUpdate()->first();
            $hash = hash('sha256', json_encode($d));
            $old = DB::table('sales')->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                if ($old->user_id !== $u->id || $old->payload_hash !== $hash) {
                    self::fail('Kunci transaksi telah digunakan untuk permintaan berbeda.');
                }

                return $old->id;
            }
            $shift = DB::table('shifts')->where('user_id', $u->id)->whereNull('closed_at')->lockForUpdate()->first();
            if (! $shift) {
                self::fail('Buka shift terlebih dahulu.');
            }
            if (bccomp($d['discount'] ?? '0', '0', 2) > 0) {
                Access::check($u, 'discount');
            }
            $total = '0';
            $items = [];
            $rx = null;
            if (! empty($d['prescription_id'])) {
                $rx = DB::table('prescriptions')->where('id', $d['prescription_id'])->lockForUpdate()->first();
                if (! $rx || ! in_array($rx->status, ['received', 'reviewed', 'prepared', 'checked'])) {
                    self::fail('Resep tidak tersedia untuk pembayaran.');
                }
                self::requirePrescriptionReady($rx);
            }
            $ids = collect($d['items'])->pluck('product_id')->unique()->sort()->values();
            DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($d['items'] as $i) {
                $p = DB::table('products')->find($i['product_id']);
                $unit = DB::table('product_units')->where('product_id', $p->id)->find($i['unit_id']);
                if (! $p->active || ! $unit) {
                    self::fail('Produk atau satuan tidak valid.');
                }
                if ($p->special_control) {
                    self::fail('Produk pengawasan khusus diblok sampai aturan diverifikasi.');
                }
                $ri = $rx ? collect(json_decode($rx->items, true))->firstWhere('product_id', $p->id) : null;
                if ($p->requires_prescription && ! $ri) {
                    self::fail('Pilih resep yang memuat obat ini sebelum mencatat pembayaran.');
                }
                if ($ri) {
                    $qb = bcmul($i['quantity'], $unit->factor, 4);
                    $previous = DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->where('s.prescription_id', $rx->id)->where('si.product_id', $p->id)->sum('si.quantity_base');
                    if (bccomp(bcadd((string) $previous, $qb, 4), (string) $ri['quantity_base'], 4) > 0) {
                        self::fail('Jumlah melebihi sisa obat yang dapat dibayar pada resep ini.');
                    }
                }
                $sub = bcmul($i['quantity'], $unit->sale_price, 2);
                $total = bcadd($total, $sub, 2);
                $items[] = ['product_id' => $p->id, 'unit_id' => $unit->id, 'quantity' => $i['quantity'], 'quantity_base' => bcmul($i['quantity'], $unit->factor, 4), 'unit_price' => $unit->sale_price, 'subtotal' => $sub];
            }
            $discount = $d['discount'] ?? '0';
            $total = bcsub($total, $discount, 2);
            if (bccomp($total, '0', 2) < 0) {
                self::fail('Diskon melebihi subtotal.');
            }
            $sum = '0';
            foreach ($d['payments'] as $pay) {
                $sum = bcadd($sum, $pay['amount'], 2);
            }
            if (bccomp($sum, $total, 2) !== 0) {
                self::fail('Total pembayaran harus sama dengan tagihan.');
            }
            foreach ($d['payments'] as $pay) {
                if (in_array($pay['method'], ['qris', 'transfer']) && (empty($pay['received']) || empty(trim($pay['reference'] ?? '')))) {
                    self::fail('Konfirmasi dana transfer/QRIS telah diterima dan isi referensi pembayarannya sebelum menyelesaikan transaksi.');
                }
            }
            $id = DB::table('sales')->insertGetId(['number' => 'PJ-'.now()->format('Ymd').'-'.strtoupper(substr($d['idempotency_key'], 0, 8)), 'idempotency_key' => $d['idempotency_key'], 'payload_hash' => $hash, 'user_id' => $u->id, 'shift_id' => $shift->id, 'prescription_id' => $d['prescription_id'] ?? null, 'total' => $total, 'discount' => $discount, 'payment_status' => 'paid', 'note' => $d['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($items as $i) {
                DB::table('sale_items')->insert(['sale_id' => $id, ...$i]);
            }foreach ($d['payments'] as $pay) {
                $t = $pay['tendered'] ?? $pay['amount'];
                if ($pay['method'] === 'cash' && bccomp($t, $pay['amount'], 2) < 0) {
                    self::fail('Uang tunai kurang.');
                }
                DB::table('payments')->insert(['sale_id' => $id, 'method' => $pay['method'], 'amount' => $pay['amount'], 'tendered' => $t, 'change' => $pay['method'] === 'cash' ? bcsub($t, $pay['amount'], 2) : '0', 'reference' => $pay['reference'] ?? null, 'verified' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            self::deliver($id, $u);
            self::audit($u, 'checkout', 'sale', $id, ['total' => $total, 'methods' => array_column($d['payments'], 'method')]);

            return $id;
        }, 3);
    }

    public static function deliver(int $id, User $u): void
    {
        DB::transaction(function () use ($id, $u) {
            $sale = DB::table('sales')->where('id', $id)->lockForUpdate()->first();
            abort_unless($sale, 404);
            $rx = null;
            if ($sale->prescription_id) {
                if (in_array('pharmacy', Access::permissions($u))) {
                    Access::check($u, 'pharmacy');
                } else {
                    Access::check($u, 'sales');
                    abort_unless($sale->user_id === $u->id, 403, 'Transaksi ini milik kasir lain.');
                }
            } else {
                Access::check($u, 'sales');
                abort_unless($sale->user_id === $u->id, 403, 'Transaksi ini milik kasir lain.');
            }
            if ($sale->delivery_status === 'delivered') {
                return;
            }
            if ($sale->payment_status !== 'paid') {
                self::fail('Konfirmasi pembayaran diterima sebelum menyelesaikan transaksi.');
            }
            if ($sale->prescription_id) {
                $rx = DB::table('prescriptions')->where('id', $sale->prescription_id)->lockForUpdate()->first();
                self::requirePrescriptionReady($rx);
            }
            $items = DB::table('sale_items')->where('sale_id', $id)->orderBy('product_id')->get();
            DB::table('products')->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
            $cost = '0';
            foreach ($items as $i) {
                $cost = bcadd($cost, self::allocate($i->product_id, $i->quantity_base, 'sale', $id, $u, $i->id), 2);
            }
            DB::table('sales')->where('id', $id)->update(['delivery_status' => 'delivered', 'delivered_by' => $u->id, 'cost' => $cost, 'updated_at' => now()]);
            self::audit($u, 'deliver', 'sale', $id, $rx ? ['prescription_id' => $rx->id, 'reviewed_by' => $rx->reviewed_by, 'checked_by' => $rx->checked_by] : []);
        }, 3);
    }

    public static function approveCount(int $id, User $u): void
    {
        Access::check($u, 'stock.approve');
        DB::transaction(function () use ($id, $u) {
            $c = DB::table('stock_counts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($c, 404);
            if ($c->status === 'posted') {
                return;
            }
            if ($c->snapshot_ledger_id === null) {
                self::fail('Opname lama belum memiliki versi mutasi. Petugas perlu menghitung ulang terlebih dahulu.');
            }
            if ($c->status !== 'pending') {
                self::fail('Hitungan harus dikirim petugas sebelum disetujui.');
            }
            if ($c->counted === null || ! $c->reason) {
                self::fail('Isi hitungan fisik dan alasan.');
            }
            $l = DB::table('inventory_layers')->where('id', $c->layer_id)->lockForUpdate()->first();
            if (bccomp($l->quantity, $c->snapshot, 4) !== 0 || (int) (DB::table('stock_ledger')->where('layer_id', $l->id)->max('id') ?? 0) !== (int) $c->snapshot_ledger_id) {
                self::fail('Ada mutasi sejak snapshot opname. Petugas harus memuat ulang snapshot dan menghitung ulang.');
            }self::move($l->id, bcsub($c->counted, $c->snapshot, 4), 'count', 'stock_count', $id, $u, $c->reason);
            DB::table('stock_counts')->where('id', $id)->update(['status' => 'posted', 'approved_by' => $u->id, 'updated_at' => now()]);
            self::audit($u, 'approve', 'stock_count', $id);
        });
    }
}
