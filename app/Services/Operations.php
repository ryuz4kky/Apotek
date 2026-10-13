<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Operations
{
    public static function purchase(array $d, User $u): int
    {
        Access::check($u, 'purchase');

        return DB::transaction(function () use ($d, $u) {
            DB::table('users')->where('id', $u->id)->lockForUpdate()->first();
            $hash = hash('sha256', json_encode($d));
            $old = DB::table('purchase_orders')->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                if ($old->created_by !== $u->id || $old->payload_hash !== $hash) {
                    Inventory::fail('Kunci pesanan dipakai untuk data berbeda.');
                }

                return $old->id;
            }
            $id = DB::table('purchase_orders')->insertGetId(['number' => 'PO-'.strtoupper(substr((string) Str::uuid(), 0, 12)), 'supplier_id' => $d['supplier_id'], 'idempotency_key' => $d['idempotency_key'], 'payload_hash' => $hash, 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($d['items'] as $i) {
                $unit = DB::table('product_units')->where('product_id', $i['product_id'])->find($i['unit_id']);
                $p = DB::table('products')->find($i['product_id']);
                if (! $unit || ! $p->active || $p->special_control) {
                    Inventory::fail('Produk/satuan tidak tersedia untuk pesanan pembelian umum.');
                }
                DB::table('purchase_order_items')->insert(['purchase_order_id' => $id, 'product_id' => $p->id, 'quantity_base' => bcmul($i['quantity'], $unit->factor, 4)]);
            }
            Inventory::audit($u, 'purchase.create', 'purchase_order', $id);

            return $id;
        });
    }

    public static function approvePurchase(int $id, User $u): void
    {
        Access::check($u, 'purchase.approve');
        DB::transaction(function () use ($id, $u) {
            $o = DB::table('purchase_orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($o, 404);
            if ($o->status !== 'draft') {
                Inventory::fail('Pesanan sudah disetujui atau diproses.');
            }
            $s = DB::table('suppliers')->find($o->supplier_id);
            if (! $s->verified || ! $s->license_expires || $s->license_expires < now()->timezone(config('pharmacy.timezone'))->toDateString()) {
                Inventory::fail('Pemasok harus terverifikasi dengan dokumen yang masih berlaku.');
            }
            DB::table('purchase_orders')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $u->id, 'updated_at' => now()]);
            Inventory::audit($u, 'purchase.approve', 'purchase_order', $id);
        });
    }

    public static function advancePrescription(array $d, User $u): void
    {
        Access::check($u, 'prescription.view');
        DB::transaction(function () use ($d, $u) {
            $rx = DB::table('prescriptions')->where('id', $d['id'])->lockForUpdate()->first();
            if ($rx->status !== $d['expected_status']) {
                Inventory::fail('Status resep telah berubah. Muat ulang sebelum melanjutkan proses.');
            }
            $next = match ($rx->status) {
                'received' => 'reviewed','reviewed' => 'prepared','prepared' => 'checked',default => null
            };
            if (! $next) {
                Inventory::fail('Proses resep sudah selesai atau tidak dapat dilanjutkan.');
            }
            Access::check($u, $next === 'prepared' ? 'prepare' : 'pharmacy');
            if ($next === 'prepared' && in_array($u->role, ['apoteker', 'apoteker_pj'])) {
                Access::check($u, 'pharmacy');
            }
            $update = ['status' => $next, 'review' => $d['review'], 'updated_at' => now()];
            if ($next === 'reviewed') {
                $update['reviewed_by'] = $u->id;
            }
            if ($next === 'checked') {
                $update['checked_by'] = $u->id;
            }
            DB::table('prescriptions')->where('id', $rx->id)->update($update);
            DB::table('prescription_events')->insert(['prescription_id' => $rx->id, 'from_status' => $rx->status, 'to_status' => $next, 'note' => $d['review'], 'user_id' => $u->id, 'created_at' => now()]);
            Inventory::audit($u, 'prescription.transition', 'prescription', $rx->id, ['from' => $rx->status, 'to' => $next]);
        });
    }

    public static function startCount(int $layer, User $u): int
    {
        Access::check($u, 'stock.manage');

        return DB::transaction(function () use ($layer, $u) {
            $l = DB::table('inventory_layers')->where('id', $layer)->lockForUpdate()->first();
            if ($l->status === 'pending') {
                Inventory::fail('Penerimaan belum diposting; tidak dapat diopname.');
            }
            if (DB::table('stock_counts')->where('layer_id', $layer)->whereIn('status', ['draft', 'pending'])->exists()) {
                Inventory::fail('Masih ada opname aktif untuk batch/lokasi ini.');
            }

            return DB::table('stock_counts')->insertGetId(['layer_id' => $layer, 'snapshot' => $l->quantity, 'snapshot_ledger_id' => DB::table('stock_ledger')->where('layer_id', $layer)->max('id') ?? 0, 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public static function submitCount(array $d, User $u): void
    {
        Access::check($u, 'stock.manage');
        DB::transaction(function () use ($d, $u) {
            $c = DB::table('stock_counts')->where('id', $d['id'])->lockForUpdate()->first();
            abort_unless($c->created_by === $u->id, 403, 'Hitungan dicatat oleh petugas pembuat opname.');
            if ($c->status !== 'draft') {
                Inventory::fail('Hitungan sudah dikirim; tidak dapat ditimpa.');
            }
            DB::table('stock_counts')->where('id', $c->id)->update(['counted' => $d['counted'], 'reason' => $d['reason'], 'status' => 'pending', 'updated_at' => now()]);
        });
    }

    public static function recount(int $id, User $u): void
    {
        Access::check($u, 'stock.manage');
        DB::transaction(function () use ($id, $u) {
            $c = DB::table('stock_counts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($c->created_by === $u->id, 403);
            if ($c->status === 'posted') {
                Inventory::fail('Opname yang sudah diposting tidak dapat diubah.');
            }
            $l = DB::table('inventory_layers')->where('id', $c->layer_id)->lockForUpdate()->first();
            DB::table('stock_counts')->where('id', $id)->update(['snapshot' => $l->quantity, 'snapshot_ledger_id' => DB::table('stock_ledger')->where('layer_id', $l->id)->max('id') ?? 0, 'counted' => null, 'reason' => null, 'status' => 'draft', 'updated_at' => now()]);
            Inventory::audit($u, 'count.recount', 'stock_count', $id);
        });
    }

    public static function cash(array $d, User $u): int
    {
        Access::check($u, 'shift');

        return DB::transaction(function () use ($d, $u) {
            DB::table('users')->where('id', $u->id)->lockForUpdate()->first();
            $hash = hash('sha256', json_encode($d));
            $old = DB::table('cash_movements')->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                if ($old->user_id !== $u->id || $old->payload_hash !== $hash) {
                    Inventory::fail('Kunci kas telah digunakan untuk data berbeda.');
                }

                return $old->id;
            }
            $s = DB::table('shifts')->where('user_id', $u->id)->whereNull('closed_at')->lockForUpdate()->first();
            if (! $s) {
                Inventory::fail('Buka shift terlebih dahulu.');
            }
            $id = DB::table('cash_movements')->insertGetId(['shift_id' => $s->id, 'amount' => $d['amount'], 'reason' => $d['reason'], 'idempotency_key' => $d['idempotency_key'], 'payload_hash' => $hash, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            Inventory::audit($u, 'cash', 'cash_movement', $id, ['amount' => $d['amount']]);

            return $id;
        });
    }

    public static function supplierPay(array $d, User $u): int
    {
        Access::check($u, 'finance');

        return DB::transaction(function () use ($d, $u) {
            $r = DB::table('receipts')->where('id', $d['receipt_id'])->lockForUpdate()->first();
            $hash = hash('sha256', json_encode($d));
            $old = DB::table('supplier_payments')->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                if ($old->user_id !== $u->id || $old->payload_hash !== $hash) {
                    Inventory::fail('Kunci pembayaran pemasok telah digunakan untuk data berbeda.');
                }

                return $old->id;
            }
            if ($r->status !== 'posted') {
                Inventory::fail('Penerimaan belum diposting.');
            }
            $paid = DB::table('supplier_payments')->where('receipt_id', $r->id)->sum('amount');
            if (bccomp(bcadd((string) $paid, $d['amount'], 2), $r->total_cost, 2) > 0) {
                Inventory::fail('Pembayaran melebihi sisa utang.');
            }
            $id = DB::table('supplier_payments')->insertGetId([...$d, 'payload_hash' => $hash, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            Inventory::audit($u, 'supplier.pay', 'supplier_payment', $id, ['receipt_id' => $r->id, 'amount' => $d['amount']]);

            return $id;
        });
    }

    public static function intakeReturn(array $d, User $u): int
    {
        Access::check($u, 'return.intake');

        return DB::transaction(function () use ($d, $u) {
            $item = DB::table('sale_items')->where('id', $d['sale_item_id'])->lockForUpdate()->first();
            $sale = DB::table('sales')->where('id', $item->sale_id)->lockForUpdate()->first();
            abort_unless($sale->user_id === $u->id, 403, 'Gunakan transaksi dalam ruang lingkup kasir Anda.');
            $hash = hash('sha256', json_encode($d));
            $old = DB::table('customer_returns')->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                if ($old->payload_hash !== $hash || $old->created_by !== $u->id) {
                    Inventory::fail('Kunci retur telah digunakan untuk data berbeda.');
                }

                return $old->id;
            }
            if ($sale->delivery_status !== 'delivered') {
                Inventory::fail('Barang belum diserahkan pada transaksi asal.');
            }
            $qty = $d['quantity_base'];
            if (bccomp(bcadd($item->returned_base, $qty, 4), $item->quantity_base, 4) > 0) {
                Inventory::fail('Jumlah retur melebihi sisa jumlah yang dijual.');
            }
            $location = DB::table('locations')->find($d['location_id']);
            if ($location->type !== 'karantina') {
                Inventory::fail('Pilih lokasi area karantina untuk barang retur.');
            }
            $gross = bcadd($sale->total, $sale->discount, 2);
            $net = bccomp($gross, '0', 2) === 0 ? '0' : bcmul($item->subtotal, bcdiv($sale->total, $gross, 8), 2);
            $prior = (string) DB::table('customer_returns')->where('sale_item_id', $item->id)->sum('amount');
            $full = bccomp(bcadd($item->returned_base, $qty, 4), $item->quantity_base, 4) === 0;
            $amount = $full ? bcsub($net, $prior, 2) : bcmul(bcdiv($net, $item->quantity_base, 8), $qty, 2);
            $id = DB::table('customer_returns')->insertGetId(['sale_id' => $sale->id, 'sale_item_id' => $item->id, 'idempotency_key' => $d['idempotency_key'], 'payload_hash' => $hash, 'quantity_base' => $qty, 'amount' => $amount, 'reason' => $d['reason'], 'condition' => $d['condition'], 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            $left = $qty;
            foreach (DB::table('sale_allocations')->where('sale_item_id', $item->id)->orderBy('id')->lockForUpdate()->get() as $a) {
                if (bccomp($left, '0', 4) <= 0) {
                    break;
                }
                $remaining = bcsub($a->quantity, $a->returned, 4);
                $take = bccomp($left, $remaining, 4) > 0 ? $remaining : $left;
                if (bccomp($take, '0', 4) <= 0) {
                    continue;
                }
                $l = DB::table('inventory_layers')->find($a->layer_id);
                $new = DB::table('inventory_layers')->insertGetId(['batch_id' => $l->batch_id, 'location_id' => $location->id, 'receipt_id' => $l->receipt_id, 'received_quantity' => $take, 'quantity' => '0', 'cost_per_base' => $l->cost_per_base, 'status' => 'quarantine', 'created_at' => now(), 'updated_at' => now()]);
                Inventory::move($new, $take, 'customer_return', 'customer_return', $id, $u, $d['reason']);
                DB::table('customer_return_layers')->insert(['customer_return_id' => $id, 'layer_id' => $new]);
                DB::table('sale_allocations')->where('id', $a->id)->update(['returned' => bcadd($a->returned, $take, 4)]);
                $left = bcsub($left, $take, 4);
            }
            if (bccomp($left, '0', 4) > 0) {
                Inventory::fail('Alokasi batch asal tidak cukup; hubungi pengelola sistem.');
            }
            DB::table('sale_items')->where('id', $item->id)->update(['returned_base' => bcadd($item->returned_base, $qty, 4)]);
            Inventory::audit($u, 'return.intake', 'customer_return', $id, ['sale_id' => $sale->id, 'quantity' => $qty]);

            return $id;
        });
    }

    public static function assessReturn(array $d, User $u): void
    {
        Access::check($u, 'return.assess');
        DB::transaction(function () use ($d, $u) {
            $r = DB::table('customer_returns')->where('id', $d['id'])->lockForUpdate()->first();
            if ($r->assessment !== 'pending') {
                Inventory::fail('Penilaian retur sudah dicatat; tidak dapat ditimpa.');
            }
            DB::table('customer_returns')->where('id', $r->id)->update(['assessment' => $d['assessment'], 'assessment_note' => $d['assessment_note'], 'assessed_by' => $u->id, 'updated_at' => now()]);
            if ($d['assessment'] === 'unfit') {
                DB::table('inventory_layers')->whereIn('id', DB::table('customer_return_layers')->where('customer_return_id', $r->id)->pluck('layer_id'))->update(['status' => 'damaged']);
            }
            Inventory::audit($u, 'return.assess', 'customer_return', $r->id, ['assessment' => $d['assessment']]);
        });
    }

    public static function refund(array $d, User $u): int
    {
        Access::check($u, 'refund');

        return DB::transaction(function () use ($d, $u) {
            $r = DB::table('customer_returns')->where('id', $d['customer_return_id'])->lockForUpdate()->first();
            $hash = hash('sha256', json_encode($d));
            $old = DB::table('refunds')->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                if ($old->payload_hash !== $hash || $old->approved_by !== $u->id) {
                    Inventory::fail('Kunci refund telah digunakan untuk data berbeda.');
                }

                return $old->id;
            }
            if (DB::table('refunds')->where('customer_return_id', $r->id)->exists()) {
                Inventory::fail('Pengembalian dana retur ini sudah dicatat.');
            }
            $id = DB::table('refunds')->insertGetId(['sale_id' => $r->sale_id, 'sale_item_id' => $r->sale_item_id, 'customer_return_id' => $r->id, 'idempotency_key' => $d['idempotency_key'], 'payload_hash' => $hash, 'quantity_base' => $r->quantity_base, 'amount' => $r->amount, 'method' => 'bank', 'reference' => $d['reference'], 'reason' => $d['reason'], 'approved_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            Inventory::audit($u, 'refund', 'refund', $id, ['customer_return_id' => $r->id, 'amount' => $r->amount]);

            return $id;
        });
    }
}
