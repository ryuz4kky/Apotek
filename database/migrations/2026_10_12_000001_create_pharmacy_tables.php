<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('kasir');
            $t->json('permissions')->nullable();
            $t->date('credential_expires')->nullable();
            $t->boolean('active')->default(true);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('license')->nullable();
            $t->date('license_expires')->nullable();
            $t->text('address')->nullable();
            $t->string('contact')->nullable();
            $t->boolean('verified')->default(false);
            $t->timestamps();
        });
        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('type')->default('rak');
            $t->decimal('temperature_min', 8, 2)->nullable();
            $t->decimal('temperature_max', 8, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku')->unique();
            $t->string('barcode')->nullable()->unique();
            $t->string('name')->index();
            $t->string('generic')->nullable();
            $t->string('strength')->nullable();
            $t->string('dosage_form')->nullable();
            $t->string('manufacturer')->nullable();
            $t->string('registration')->nullable();
            $t->string('category')->default('obat_bebas');
            $t->string('base_unit');
            $t->boolean('requires_prescription')->default(false);
            $t->boolean('special_control')->default(false);
            $t->decimal('minimum_stock', 18, 4)->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('product_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained();
            $t->string('name');
            $t->decimal('factor', 18, 4);
            $t->decimal('sale_price', 18, 2);
            $t->unique(['product_id', 'name']);
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('supplier_id')->constrained();
            $t->string('status')->default('draft');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->decimal('quantity_base', 18, 4);
        });
        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('supplier_id')->constrained();
            $t->foreignId('purchase_order_id')->nullable()->constrained();
            $t->string('invoice');
            $t->string('status')->default('pending');
            $t->date('due_date');
            $t->json('checklist');
            $t->decimal('total_cost', 18, 2)->default(0);
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['supplier_id', 'invoice']);
        });
        Schema::create('batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained();
            $t->string('number');
            $t->date('expires_at');
            $t->boolean('recalled')->default(false);
            $t->unique(['product_id', 'number', 'expires_at']);
        });
        Schema::create('inventory_layers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained();
            $t->foreignId('location_id')->constrained();
            $t->foreignId('receipt_id')->nullable()->constrained();
            $t->decimal('quantity', 18, 4)->default(0);
            $t->decimal('received_quantity', 18, 4);
            $t->decimal('cost_per_base', 18, 6);
            $t->string('status')->default('pending');
            $t->timestamps();
        });
        Schema::create('stock_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('layer_id')->constrained('inventory_layers');
            $t->decimal('delta', 18, 4);
            $t->decimal('balance_after', 18, 4);
            $t->string('kind');
            $t->string('source_type');
            $t->unsignedBigInteger('source_id');
            $t->foreignId('user_id')->constrained();
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['source_type', 'source_id']);
        });
        Schema::create('prescriptions', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->date('date');
            $t->text('patient_name');
            $t->string('doctor');
            $t->text('notes')->nullable();
            $t->string('status')->default('received');
            $t->json('items');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->text('review')->nullable();
            $t->foreignId('checked_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->decimal('opening_cash', 18, 2);
            $t->decimal('expected_cash', 18, 2)->nullable();
            $t->decimal('counted_cash', 18, 2)->nullable();
            $t->decimal('difference', 18, 2)->nullable();
            $t->text('note')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->uuid('idempotency_key')->unique();
            $t->string('payload_hash');
            $t->foreignId('user_id')->constrained();
            $t->foreignId('shift_id')->constrained();
            $t->foreignId('prescription_id')->nullable()->constrained();
            $t->string('payment_status')->default('paid');
            $t->string('delivery_status')->default('pending');
            $t->decimal('total', 18, 2);
            $t->decimal('discount', 18, 2)->default(0);
            $t->decimal('cost', 18, 2)->default(0);
            $t->text('note')->nullable();
            $t->foreignId('delivered_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('unit_id')->constrained('product_units');
            $t->decimal('quantity', 18, 4);
            $t->decimal('quantity_base', 18, 4);
            $t->decimal('unit_price', 18, 2);
            $t->decimal('subtotal', 18, 2);
            $t->decimal('returned_base', 18, 4)->default(0);
        });
        Schema::create('sale_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_item_id')->constrained();
            $t->foreignId('layer_id')->constrained('inventory_layers');
            $t->decimal('quantity', 18, 4);
            $t->decimal('cost', 18, 2);
            $t->decimal('returned', 18, 4)->default(0);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained();
            $t->string('method');
            $t->decimal('amount', 18, 2);
            $t->decimal('tendered', 18, 2);
            $t->decimal('change', 18, 2)->default(0);
            $t->string('reference')->nullable();
            $t->boolean('verified')->default(true);
            $t->timestamps();
        });
        Schema::create('cash_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shift_id')->constrained();
            $t->decimal('amount', 18, 2);
            $t->text('reason');
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained();
            $t->foreignId('sale_item_id')->constrained();
            $t->uuid('idempotency_key')->unique();
            $t->decimal('quantity_base', 18, 4);
            $t->decimal('amount', 18, 2);
            $t->string('method');
            $t->text('reason');
            $t->foreignId('approved_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('stock_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('layer_id')->constrained('inventory_layers');
            $t->decimal('snapshot', 18, 4);
            $t->decimal('counted', 18, 4)->nullable();
            $t->string('status')->default('draft');
            $t->text('reason')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('temperatures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained();
            $t->decimal('value', 8, 2);
            $t->decimal('minimum', 8, 2);
            $t->decimal('maximum', 8, 2);
            $t->boolean('deviation');
            $t->text('action')->nullable();
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receipt_id')->constrained();
            $t->decimal('amount', 18, 2);
            $t->string('reference');
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action');
            $t->string('source_type');
            $t->unsignedBigInteger('source_id')->nullable();
            $t->json('data')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_layers ADD CONSTRAINT nonnegative_quantity CHECK (quantity >= 0)');
            DB::statement('ALTER TABLE product_units ADD CONSTRAINT positive_factor CHECK (factor > 0 AND sale_price >= 0)');
            DB::statement('CREATE UNIQUE INDEX one_open_shift_per_user ON shifts (user_id) WHERE closed_at IS NULL');
        }
    }

    public function down(): void
    {
        foreach (['audit_logs', 'supplier_payments', 'temperatures', 'stock_counts', 'refunds', 'cash_movements', 'payments', 'sale_allocations', 'sale_items', 'sales', 'shifts', 'prescriptions', 'stock_ledger', 'inventory_layers', 'batches', 'receipts', 'purchase_order_items', 'purchase_orders', 'product_units', 'products', 'locations', 'suppliers', 'settings'] as $name) {
            Schema::dropIfExists($name);
        }Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'permissions', 'credential_expires', 'active']));
    }
};
