<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->uuid('idempotency_key')->nullable()->unique();
            $t->string('payload_hash')->nullable();
        });
        Schema::create('receipt_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receipt_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('unit_id')->constrained('product_units');
            $t->foreignId('layer_id')->unique()->constrained('inventory_layers');
            $t->decimal('quantity', 18, 4);
            $t->decimal('quantity_base', 18, 4);
            $t->decimal('unit_cost', 18, 2);
            $t->boolean('legacy_base_unit')->default(false);
        });
        DB::table('inventory_layers as l')->join('batches as b', 'b.id', '=', 'l.batch_id')->join('products as p', 'p.id', '=', 'b.product_id')->whereNotNull('l.receipt_id')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stock_ledger as s')->whereColumn('s.layer_id', 'l.id')->where('s.kind', 'customer_return'))->select('l.*', 'b.product_id', 'p.base_unit')->orderBy('l.id')->chunk(200, function ($rows) {
            foreach ($rows as $l) {
                $unit = DB::table('product_units')->where('product_id', $l->product_id)->where('name', $l->base_unit)->where('factor', 1)->value('id');
                if ($unit) {
                    DB::table('receipt_items')->insert(['receipt_id' => $l->receipt_id, 'product_id' => $l->product_id, 'unit_id' => $unit, 'layer_id' => $l->id, 'quantity' => $l->received_quantity, 'quantity_base' => $l->received_quantity, 'unit_cost' => $l->cost_per_base, 'legacy_base_unit' => true]);
                }
            }
        });
        Schema::table('stock_counts', function (Blueprint $t) {
            $t->unsignedBigInteger('snapshot_ledger_id')->nullable();
        });
        DB::table('stock_counts')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('stock_counts')->where('id', $row->id)->update(['snapshot_ledger_id' => DB::table('stock_ledger')->where('layer_id', $row->layer_id)->where('created_at', '<=', $row->created_at)->max('id') ?? 0]);
            }
        });
        DB::table('stock_counts')->whereIn('status', ['draft', 'pending'])->update(['snapshot_ledger_id' => null]);
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->uuid('idempotency_key')->nullable()->unique();
            $t->string('payload_hash')->nullable();
        });
        Schema::table('cash_movements', function (Blueprint $t) {
            $t->uuid('idempotency_key')->nullable()->unique();
            $t->string('payload_hash')->nullable();
        });
        Schema::create('prescription_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('prescription_id')->constrained();
            $t->string('from_status')->nullable();
            $t->string('to_status');
            $t->text('note');
            $t->foreignId('user_id')->constrained();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['prescription_id', 'to_status']);
        });
        Schema::create('customer_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained();
            $t->foreignId('sale_item_id')->constrained();
            $t->uuid('idempotency_key')->unique();
            $t->string('payload_hash');
            $t->decimal('quantity_base', 18, 4);
            $t->decimal('amount', 18, 2);
            $t->text('reason');
            $t->text('condition');
            $t->string('assessment')->default('pending');
            $t->text('assessment_note')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('assessed_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('customer_return_layers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_return_id')->constrained();
            $t->foreignId('layer_id')->constrained('inventory_layers');
        });
        Schema::table('refunds', function (Blueprint $t) {
            $t->foreignId('customer_return_id')->nullable()->unique()->constrained();
            $t->string('reference')->nullable();
            $t->string('payload_hash')->nullable();
        });
        DB::table('refunds')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $r) {
                $id = DB::table('customer_returns')->insertGetId(['sale_id' => $r->sale_id, 'sale_item_id' => $r->sale_item_id, 'idempotency_key' => $r->idempotency_key, 'payload_hash' => 'legacy', 'quantity_base' => $r->quantity_base, 'amount' => $r->amount, 'reason' => $r->reason, 'condition' => 'Retur lama; kondisi tidak tercatat terpisah.', 'created_by' => $r->approved_by, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at]);
                DB::table('refunds')->where('id', $r->id)->update(['customer_return_id' => $id]);
                foreach (DB::table('stock_ledger')->where('source_type', 'refund')->where('source_id', $r->id)->where('kind', 'customer_return')->get() as $l) {
                    DB::table('customer_return_layers')->insert(['customer_return_id' => $id, 'layer_id' => $l->layer_id]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_items');
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn(['idempotency_key', 'payload_hash']));
        Schema::table('refunds', fn (Blueprint $t) => $t->dropColumn(['customer_return_id', 'reference', 'payload_hash']));
        Schema::dropIfExists('customer_return_layers');
        Schema::dropIfExists('customer_returns');
        Schema::dropIfExists('prescription_events');
        Schema::table('stock_counts', fn (Blueprint $t) => $t->dropColumn('snapshot_ledger_id'));
        Schema::table('supplier_payments', fn (Blueprint $t) => $t->dropColumn(['idempotency_key', 'payload_hash']));
        Schema::table('cash_movements', fn (Blueprint $t) => $t->dropColumn(['idempotency_key', 'payload_hash']));
    }
};
