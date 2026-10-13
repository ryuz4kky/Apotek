<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Inventory;
use App\Services\Operations;
use App\Services\PrescriptionSearch;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PharmacyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed();
    }

    private function user(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }

    private function shift(User $u): int
    {
        return DB::table('shifts')->insertGetId(['user_id' => $u->id, 'opening_cash' => '100000', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(int $product, string $quantity = '1', string $unit = 'strip', string $method = 'cash'): array
    {
        $u = DB::table('product_units')->where('product_id', $product)->where('name', $unit)->first();
        $total = bcmul($u->sale_price, $quantity, 2);

        return ['idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $product, 'unit_id' => $u->id, 'quantity' => $quantity]], 'payments' => [['method' => $method, 'amount' => $total, 'tendered' => $total]], 'discount' => '0'];
    }

    public function test_guests_and_backend_permissions(): void
    {
        $this->get('/')->assertRedirect('/login');
        $u = $this->user('kasir');
        $this->actingAs($u)->postJson('/actions/product', [])->assertForbidden();
        $this->actingAs($this->user('admin'))->postJson('/actions/receipt.verify', ['id' => 1])->assertForbidden();
        $this->actingAs($u)->get('/products')->assertOk();
    }

    public function test_purchase_two_boxes_posts_two_hundred_base_units(): void
    {
        $u = $this->user('gudang');
        $p = DB::table('products')->where('sku', 'PAR-500')->first();
        $unit = DB::table('product_units')->where('product_id', $p->id)->where('name', 'dus')->first();
        $this->actingAs($u)->post('/actions/receipt', ['supplier_id' => 1, 'invoice' => 'TEST-001', 'due_date' => now()->addDays(10)->toDateString(), 'location_id' => 1, 'checklist' => ['packaging' => true, 'documents' => true, 'quantity' => true], 'items' => [['product_id' => $p->id, 'unit_id' => $unit->id, 'quantity' => '2', 'batch' => 'TEST-BOX', 'expires_at' => now()->addYear()->toDateString(), 'unit_cost' => '10000']]])->assertSessionHasNoErrors();
        $r = DB::table('receipts')->where('invoice', 'TEST-001')->first();
        $layer = DB::table('inventory_layers')->where('receipt_id', $r->id)->first();
        $this->assertEquals('0.0000', $layer->quantity);
        Inventory::verifyReceipt($r->id, $this->user('apoteker_pj'));
        $this->assertEquals('200.0000', DB::table('inventory_layers')->find($layer->id)->quantity);
        Inventory::verifyReceipt($r->id, $this->user('apoteker_pj'));
        $this->assertEquals(1, DB::table('stock_ledger')->where('source_id', $r->id)->where('kind', 'receipt')->count());
    }

    public function test_sale_strip_fefo_and_idempotency(): void
    {
        $u = $this->user('kasir');
        $this->shift($u);
        $p = DB::table('products')->where('sku', 'PAR-500')->first();
        $b = DB::table('batches')->insertGetId(['product_id' => $p->id, 'number' => 'EARLIER', 'expires_at' => now()->addDays(10)->toDateString()]);
        $l = DB::table('inventory_layers')->insertGetId(['batch_id' => $b, 'location_id' => 1, 'quantity' => '20', 'received_quantity' => '20', 'cost_per_base' => '200', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $d = $this->payload($p->id);
        $id = Inventory::checkout($d, $u);
        $this->assertEquals('10.0000', DB::table('inventory_layers')->find($l)->quantity);
        $this->assertEquals($l, DB::table('sale_allocations')->value('layer_id'));
        $this->assertSame($id, Inventory::checkout($d, $u));
        $this->assertEquals(1, DB::table('sales')->count());
        $this->assertEquals(1, DB::table('payments')->count());
        $this->assertEquals('10.0000', DB::table('inventory_layers')->find($l)->quantity);
    }

    public function test_non_sellable_batches_are_blocked(): void
    {
        $p = DB::table('products')->where('sku', 'PAR-500')->first();
        $l = DB::table('inventory_layers as l')->join('batches as b', 'b.id', '=', 'l.batch_id')->where('b.product_id', $p->id)->value('l.id');
        $u = $this->user('kasir');
        $this->shift($u);
        foreach (['quarantine', 'expired', 'recalled'] as $state) {
            DB::table('inventory_layers')->where('id', $l)->update(['status' => $state === 'quarantine' ? 'quarantine' : 'available']);
            $batch = DB::table('inventory_layers')->find($l)->batch_id;
            DB::table('batches')->where('id', $batch)->update(['expires_at' => $state === 'expired' ? now()->subDay()->toDateString() : now()->addYear()->toDateString(), 'recalled' => $state === 'recalled']);
            $this->actingAs($u)->postJson('/actions/sale', $this->payload($p->id))->assertUnprocessable();
            $this->assertEquals(0, DB::table('sales')->count());
        }
    }

    public function test_insufficient_stock_rolls_back_payment_and_sale(): void
    {
        $u = $this->user('kasir');
        $this->shift($u);
        $p = DB::table('products')->where('sku', 'PAR-500')->first();
        $this->actingAs($u)->postJson('/actions/sale', $this->payload($p->id, '999'))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_prescription_requires_review_prepare_and_check(): void
    {
        $cashier = $this->user('kasir');
        $this->shift($cashier);
        $product = DB::table('products')->where('sku', 'AMX-500')->first();
        $rx = $this->prescriptionFor($product->id, '10', 'received');
        $payload = $this->payload($product->id);
        $payload['prescription_id'] = $rx;
        $pharmacist = $this->user('apoteker_pj');
        for ($step = 0; $step < 3; $step++) {
            $this->actingAs($cashier)->postJson('/actions/sale', $payload)->assertUnprocessable();
            $this->assertDatabaseCount('sales', 0);
            $this->assertDatabaseCount('payments', 0);
            $this->actingAs($pharmacist)->post('/actions/prescription.advance', ['id' => $rx, 'expected_status' => DB::table('prescriptions')->find($rx)->status, 'review' => 'Pemeriksaan sintetis'])->assertSessionHasNoErrors();
        }
        $id = Inventory::checkout($payload, $cashier);
        $sale = DB::table('sales')->find($id);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('delivered', $sale->delivery_status);
        $this->assertSame($cashier->id, $sale->delivered_by);
        $this->assertEquals('90.0000', Inventory::eligible($product->id)->sum('l.quantity'));
        $this->assertSame($id, Inventory::checkout($payload, $cashier));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('sale_allocations', 1);
    }

    public function test_refund_returns_stock_only_to_quarantine(): void
    {
        $u = $this->user('kasir');
        $this->shift($u);
        $p = DB::table('products')->where('sku', 'PAR-500')->first();
        $id = Inventory::checkout($this->payload($p->id), $u);
        $available = Inventory::eligible($p->id)->sum('l.quantity');
        $item = DB::table('sale_items')->where('sale_id', $id)->first();
        $d = ['sale_item_id' => $item->id, 'idempotency_key' => (string) Str::uuid(), 'quantity_base' => '10', 'location_id' => 3, 'condition' => 'sealed', 'reason' => 'Kemasan kembali, contoh'];
        $r = Operations::intakeReturn($d, $u);
        $this->assertEquals($available, Inventory::eligible($p->id)->sum('l.quantity'));
        $this->assertEquals('10.0000', DB::table('inventory_layers')->where('status', 'quarantine')->value('quantity'));
        $this->assertSame($r, Operations::intakeReturn($d, $u));
        $this->assertDatabaseCount('refunds', 0);
        $refund = ['customer_return_id' => $r, 'idempotency_key' => (string) Str::uuid(), 'reference' => 'BANK-SINTETIS', 'reason' => 'Pengembalian dana'];
        $this->actingAs($this->user('apoteker_pj'))->postJson('/actions/refund', $refund)->assertForbidden();
        $money = Operations::refund($refund, $this->user('pemilik'));
        $this->assertSame($money, Operations::refund($refund, $this->user('pemilik')));
        $this->assertDatabaseCount('refunds', 1);
        Operations::assessReturn(['id' => $r, 'assessment' => 'unfit', 'assessment_note' => 'Kemasan rusak'], $this->user('apoteker_pj'));
        $this->assertEquals('10.0000', DB::table('inventory_layers')->where('status', 'damaged')->value('quantity'));
        $refund['idempotency_key'] = (string) Str::uuid();
        $this->actingAs($this->user('pemilik'))->postJson('/actions/refund', $refund)->assertUnprocessable();
        $this->assertEquals($available, Inventory::eligible($p->id)->sum('l.quantity'));
        $d['idempotency_key'] = (string) Str::uuid();
        $this->actingAs($u)->postJson('/actions/return', $d)->assertUnprocessable();
    }

    public function test_stock_count_requires_approval_and_posts_ledger(): void
    {
        $u = $this->user('gudang');
        $this->actingAs($u)->post('/actions/count', ['layer_id' => 1]);
        $id = DB::table('stock_counts')->value('id');
        $this->actingAs($u)->post('/actions/count.submit', ['id' => $id, 'counted' => '195', 'reason' => 'Selisih hitungan contoh']);
        $this->assertEquals('200.0000', DB::table('inventory_layers')->find(1)->quantity);
        $this->actingAs($u)->postJson('/actions/count.approve', ['id' => $id])->assertForbidden();
        Inventory::approveCount($id, $this->user('apoteker_pj'));
        $this->assertEquals('195.0000', DB::table('inventory_layers')->find(1)->quantity);
        $this->assertEquals('-5.0000', DB::table('stock_ledger')->where('kind', 'count')->value('delta'));
    }

    public function test_shift_excludes_non_cash_and_manual_qris_needs_confirmation_at_checkout(): void
    {
        $cashier = $this->user('kasir');
        $shift = $this->shift($cashier);
        $product = DB::table('products')->where('sku', 'PAR-500')->first();
        Inventory::checkout($this->payload($product->id), $cashier);
        $payload = $this->payload($product->id, '1', 'strip', 'qris');
        $this->actingAs($cashier)->postJson('/actions/sale', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 1);
        $payload['payments'][0]['received'] = true;
        $this->actingAs($cashier)->postJson('/actions/sale', $payload)->assertUnprocessable();
        $payload['payments'][0]['reference'] = 'BUKTI-QRIS-SINTETIS';
        $id = Inventory::checkout($payload, $cashier);
        $this->assertEquals('paid', DB::table('sales')->find($id)->payment_status);
        $this->assertEquals('delivered', DB::table('sales')->find($id)->delivery_status);
        $this->assertTrue(DB::table('payments')->where('sale_id', $id)->value('verified'));
        $this->actingAs($cashier)->post('/actions/shift.close', ['id' => $shift, 'counted_cash' => '105000', 'note' => 'Saldo sesuai'])->assertSessionHasNoErrors();
        $this->assertEquals('105000.00', DB::table('shifts')->find($shift)->expected_cash);
        $this->assertEquals('0.00', DB::table('shifts')->find($shift)->difference);
    }

    public function test_login_rate_limiting_and_success(): void
    {
        $this->post('/login', ['email' => 'kasir@apotek.test', 'password' => env('DEMO_PASSWORD')])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_reusing_payment_key_for_different_payload_is_rejected(): void
    {
        $u = $this->user('kasir');
        $this->shift($u);
        $p = DB::table('products')->where('sku', 'PAR-500')->first();
        $d = $this->payload($p->id);
        Inventory::checkout($d, $u);
        $d['items'][0]['quantity'] = '2';
        $this->actingAs($u)->postJson('/actions/sale', $d)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_expired_pharmacist_credential_blocks_verification(): void
    {
        $u = $this->user('apoteker_pj');
        DB::table('users')->where('id', $u->id)->update(['credential_expires' => now()->subDay()->toDateString()]);
        $u->refresh();
        $this->actingAs($u)->postJson('/actions/receipt.verify', ['id' => 1])->assertForbidden();
    }

    public function test_failed_login_is_throttled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/login', ['email' => 'bruteforce@apotek.test', 'password' => 'wrong']);
            $response->assertSessionHasErrors('email');
        }
        $response->assertSessionHasErrors(['email' => 'Terlalu banyak percobaan. Coba lagi setelah satu menit.']);
    }

    public function test_pharmacist_can_create_prescription_without_changing_stock(): void
    {
        $u = $this->user('apoteker_pj');
        $p = DB::table('products')->where('sku', 'AMX-500')->first();
        $before = Inventory::eligible($p->id)->sum('l.quantity');
        $payload = ['number' => 'RX-CREATE-TEST', 'date' => now()->toDateString(), 'patient_name' => 'Pasien Sintetis', 'doctor' => 'Dokter Sintetis', 'notes' => null, 'items' => [['product_id' => $p->id, 'quantity_base' => '10']]];
        $this->actingAs($u)->from('/prescriptions')->post('/actions/prescription', $payload)->assertRedirect('/prescriptions')->assertSessionHasNoErrors();
        $rx = DB::table('prescriptions')->where('number', 'RX-CREATE-TEST')->first();
        $this->assertSame('received', $rx->status);
        $this->assertNotSame($payload['patient_name'], $rx->patient_name);
        $this->assertSame($payload['patient_name'], decrypt($rx->patient_name));
        $this->assertEquals('10', json_decode($rx->items, true)[0]['quantity_base']);
        $this->assertEquals($before, Inventory::eligible($p->id)->sum('l.quantity'));
        $this->actingAs($u)->postJson('/actions/prescription', $payload)->assertUnprocessable()->assertJsonPath('errors.number.0', 'Nomor resep sudah digunakan. Gunakan nomor resep yang berbeda.');
        $this->assertDatabaseCount('prescriptions', 1);
    }

    public function test_prescription_without_items_returns_clear_validation(): void
    {
        $this->actingAs($this->user('apoteker_pj'))->postJson('/actions/prescription', ['number' => 'RX-EMPTY-TEST', 'date' => now()->toDateString(), 'patient_name' => 'Pasien Sintetis', 'doctor' => 'Dokter Sintetis', 'items' => []])->assertUnprocessable()->assertJsonPath('errors.items.0', 'Tambahkan minimal satu obat ke daftar resep.');
        $this->assertDatabaseCount('prescriptions', 0);
    }

    private function prescriptionFor(int $product, string $quantity = '10', string $status = 'checked'): int
    {
        $id = DB::table('prescriptions')->insertGetId(['number' => 'RX-PICKER-TEST', 'date' => now()->toDateString(), 'patient_name' => encrypt('Nama Rahasia Sintetis'), 'doctor' => 'Dokter Sintetis', 'notes' => 'Catatan klinis privat', 'items' => json_encode([['product_id' => $product, 'quantity_base' => $quantity]]), 'status' => $status, 'reviewed_by' => $status === 'checked' ? $this->user('apoteker_pj')->id : null, 'checked_by' => $status === 'checked' ? $this->user('apoteker_pj')->id : null, 'created_by' => $this->user('apoteker_pj')->id, 'created_at' => now(), 'updated_at' => now()]);
        PrescriptionSearch::sync($id, 'Nama Rahasia Sintetis', 'Dokter Sintetis');

        return $id;
    }

    public function test_cashier_can_search_prescription_and_complete_checkout_without_new_pharmacy_approval(): void
    {
        $cashier = $this->user('kasir');
        $this->shift($cashier);
        $product = DB::table('products')->where('sku', 'AMX-500')->first();
        $rx = $this->prescriptionFor($product->id);
        $response = $this->actingAs($cashier)->getJson('/lookup/prescriptions?q=RX-PICKER');
        $response->assertOk()->assertJsonPath('0.ready_for_checkout', true)->assertJsonPath('0.number', 'RX-PICKER-TEST')->assertJsonPath('0.items.0.remaining_base', '10.0000')->assertJsonPath('0.patient_name', 'Nama Rahasia Sintetis')->assertJsonPath('0.doctor', 'Dokter Sintetis');
        $this->assertArrayNotHasKey('notes', $response->json('0'));
        $this->assertArrayNotHasKey('cost_per_base', $response->json('0.items.0'));
        $payload = $this->payload($product->id);
        $payload['prescription_id'] = $rx;
        $id = Inventory::checkout($payload, $cashier);
        $this->assertEquals('paid', DB::table('sales')->find($id)->payment_status);
        $this->assertEquals('delivered', DB::table('sales')->find($id)->delivery_status);
        $this->assertDatabaseCount('sale_allocations', 1);
        $this->actingAs($cashier)->getJson('/lookup/prescriptions?q=RX-PICKER')->assertJsonPath('0.items.0.remaining_base', '0.0000');
        $this->actingAs($cashier)->postJson('/actions/prescription.advance', ['id' => $rx, 'review' => 'Tidak boleh'])->assertForbidden();
        Inventory::deliver($id, $cashier);
        $this->assertDatabaseCount('sale_allocations', 1);
    }

    public function test_prescription_search_requires_sales_permission(): void
    {
        $this->getJson('/lookup/prescriptions')->assertUnauthorized();
        $this->actingAs($this->user('admin'))->getJson('/lookup/prescriptions')->assertForbidden();
    }

    public function test_prescription_limits_apply_to_non_prescription_products_too(): void
    {
        $cashier = $this->user('kasir');
        $this->shift($cashier);
        $product = DB::table('products')->where('sku', 'PAR-500')->first();
        $rx = $this->prescriptionFor($product->id, '5');
        $payload = $this->payload($product->id, '6', 'tablet');
        $payload['prescription_id'] = $rx;
        $this->actingAs($cashier)->postJson('/actions/sale', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $payload = $this->payload($product->id, '3', 'tablet');
        $payload['prescription_id'] = $rx;
        Inventory::checkout($payload, $cashier);
        $this->actingAs($cashier)->getJson('/lookup/prescriptions')->assertJsonPath('0.items.0.remaining_base', '2.0000');
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->actingAs($cashier)->postJson('/actions/sale', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_cashier_can_search_patient_and_doctor_without_clinical_details(): void
    {
        $product = DB::table('products')->where('sku', 'AMX-500')->first();
        $rx = $this->prescriptionFor($product->id);
        $this->actingAs($this->user('kasir'));
        foreach (['nama raha', 'NAMA SINTETIS', 'dokter sin', 'RX-PICKER'] as $query) {
            $response = $this->getJson('/lookup/prescriptions?q='.urlencode($query));
            $response->assertOk()->assertJsonPath('0.id', $rx)->assertJsonPath('0.patient_name', 'Nama Rahasia Sintetis');
            $this->assertArrayNotHasKey('notes', $response->json('0'));
            $this->assertArrayNotHasKey('review', $response->json('0'));
        }
        $this->getJson('/lookup/prescriptions?q=nama%20lain')->assertExactJson([]);
        $this->assertNotSame('Nama Rahasia Sintetis', DB::table('prescriptions')->find($rx)->patient_name);
        foreach (DB::table('prescription_search_tokens')->where('prescription_id', $rx)->get() as $token) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token->token);
        }
    }

    public function test_checked_flag_without_pharmacist_approval_cannot_bypass_checkout(): void
    {
        $cashier = $this->user('kasir');
        $this->shift($cashier);
        $product = DB::table('products')->where('sku', 'AMX-500')->first();
        $rx = $this->prescriptionFor($product->id);
        DB::table('prescriptions')->where('id', $rx)->update(['checked_by' => null]);
        $payload = $this->payload($product->id);
        $payload['prescription_id'] = $rx;
        $this->actingAs($cashier)->getJson('/lookup/prescriptions')->assertJsonPath('0.ready_for_checkout', false);
        $this->actingAs($cashier)->postJson('/actions/sale', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_confirmed_transfer_finishes_recipe_sale_and_insufficient_stock_rolls_back(): void
    {
        $cashier = $this->user('kasir');
        $this->shift($cashier);
        $product = DB::table('products')->where('sku', 'AMX-500')->first();
        $rx = $this->prescriptionFor($product->id, '200');
        $payload = $this->payload($product->id, '200', 'kapsul', 'transfer');
        $payload['prescription_id'] = $rx;
        $payload['payments'][0]['received'] = true;
        $payload['payments'][0]['reference'] = 'BUKTI-TRANSFER-SINTETIS';
        $this->actingAs($cashier)->postJson('/actions/sale', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('sales', 0);
        $payload = $this->payload($product->id, '10', 'kapsul', 'transfer');
        $payload['prescription_id'] = $rx;
        $payload['payments'][0]['received'] = true;
        $payload['payments'][0]['reference'] = 'BUKTI-TRANSFER-SINTETIS';
        $id = Inventory::checkout($payload, $cashier);
        $this->assertEquals('delivered', DB::table('sales')->find($id)->delivery_status);
        $this->assertEquals('paid', DB::table('sales')->find($id)->payment_status);
        $this->assertSame($id, Inventory::checkout($payload, $cashier));
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_cashier_can_finish_legacy_paid_recipe_sale_after_prior_approval(): void
    {
        $cashier = $this->user('kasir');
        $shift = $this->shift($cashier);
        $product = DB::table('products')->where('sku', 'AMX-500')->first();
        $rx = $this->prescriptionFor($product->id);
        $unit = DB::table('product_units')->where('product_id', $product->id)->where('name', 'kapsul')->first();
        $sale = DB::table('sales')->insertGetId(['number' => 'PJ-LEGACY-SINTETIS', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => 'legacy', 'user_id' => $cashier->id, 'shift_id' => $shift, 'prescription_id' => $rx, 'payment_status' => 'paid', 'delivery_status' => 'pending', 'total' => '1500', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $sale, 'product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '1', 'quantity_base' => '1', 'unit_price' => '1500', 'subtotal' => '1500']);
        DB::table('payments')->insert(['sale_id' => $sale, 'method' => 'cash', 'amount' => '1500', 'tendered' => '1500', 'verified' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prescriptions')->where('id', $rx)->update(['status' => 'prepared']);
        $this->actingAs($cashier)->postJson('/actions/sale.deliver', ['id' => $sale])->assertUnprocessable();
        $this->assertDatabaseCount('sale_allocations', 0);
        DB::table('prescriptions')->where('id', $rx)->update(['status' => 'checked']);
        $this->actingAs($cashier)->post('/actions/sale.deliver', ['id' => $sale])->assertSessionHasNoErrors();
        $this->assertEquals('delivered', DB::table('sales')->find($sale)->delivery_status);
        $this->assertEquals('99.0000', Inventory::eligible($product->id)->sum('l.quantity'));
        $other = User::factory()->create(['role' => 'kasir']);
        $this->actingAs($other)->postJson('/actions/sale.deliver', ['id' => $sale])->assertForbidden();
        $this->assertDatabaseCount('sale_allocations', 1);
    }
}
