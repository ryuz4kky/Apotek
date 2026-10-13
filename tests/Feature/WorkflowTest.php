<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Inventory;
use App\Services\Operations;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowTest extends TestCase
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

    private function receipt(string $invoice, ?int $order = null, bool $checked = true): array
    {
        $product = DB::table('products')->where('sku', 'PAR-500')->first();
        $unit = DB::table('product_units')->where('product_id', $product->id)->where('name', 'dus')->first();

        return ['purchase_order_id' => $order, 'supplier_id' => 1, 'invoice' => $invoice, 'due_date' => now()->addDays(10)->toDateString(), 'location_id' => 1, 'checklist' => ['packaging' => $checked, 'documents' => $checked, 'quantity' => $checked], 'items' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '1', 'batch' => $invoice, 'expires_at' => now()->addYear()->toDateString(), 'unit_cost' => '10000']]];
    }

    public function test_role_pages_and_sensitive_actions_are_restricted(): void
    {
        foreach (['receipts', 'stock', 'counts', 'finance', 'prescriptions', 'users'] as $page) {
            $this->actingAs($this->user('kasir'))->get('/'.$page)->assertForbidden();
        }
        foreach (['finance', 'returns', 'audit', 'purchases', 'shifts'] as $page) {
            $this->actingAs($this->user('auditor'))->get('/'.$page)->assertOk();
        }
        foreach (['supplier.pay', 'refund', 'purchase.approve', 'count.approve', 'receipt.verify'] as $action) {
            $this->actingAs($this->user('auditor'))->postJson('/actions/'.$action, [])->assertForbidden();
        }
        $this->actingAs($this->user('admin'))->postJson('/actions/prescription', [])->assertForbidden();
        $this->actingAs($this->user('apoteker_pj'))->postJson('/actions/shift.approve', [])->assertForbidden();
        $this->actingAs($this->user('pemilik'))->postJson('/actions/return.assess', [])->assertForbidden();
    }

    public function test_ttk_prepares_after_pharmacist_review_and_retries_cannot_skip_stage(): void
    {
        $ph = $this->user('apoteker_pj');
        $ttk = $this->user('ttk');
        $p = DB::table('products')->where('sku', 'AMX-500')->first();
        $this->actingAs($ph)->post('/actions/prescription', ['number' => 'RX-WORKFLOW', 'date' => now()->toDateString(), 'patient_name' => 'Pasien uji', 'doctor' => 'Dokter uji', 'items' => [['product_id' => $p->id, 'quantity_base' => '10']]])->assertSessionHasNoErrors();
        $id = DB::table('prescriptions')->value('id');
        $review = ['id' => $id, 'expected_status' => 'received', 'review' => 'Telaah apoteker'];
        $this->actingAs($ttk)->postJson('/actions/prescription.advance', $review)->assertForbidden();
        $this->actingAs($ph)->post('/actions/prescription.advance', $review)->assertSessionHasNoErrors();
        $this->actingAs($ph)->postJson('/actions/prescription.advance', $review)->assertUnprocessable();
        $this->assertDatabaseHas('prescriptions', ['id' => $id, 'status' => 'reviewed']);
        $this->actingAs($ttk)->get('/prescriptions/'.$id)->assertOk();
        $this->actingAs($ttk)->post('/actions/prescription.advance', ['id' => $id, 'expected_status' => 'reviewed', 'review' => 'Obat telah disiapkan'])->assertSessionHasNoErrors();
        $check = ['id' => $id, 'expected_status' => 'prepared', 'review' => 'Pemeriksaan akhir'];
        $this->actingAs($ttk)->postJson('/actions/prescription.advance', $check)->assertForbidden();
        $this->actingAs($ph)->post('/actions/prescription.advance', $check)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('prescription_events', 4);
        $this->assertDatabaseHas('prescription_events', ['to_status' => 'prepared', 'user_id' => $ttk->id, 'note' => 'Obat telah disiapkan']);
        $this->actingAs($this->user('kasir'))->get('/prescriptions/'.$id)->assertForbidden();
    }

    public function test_purchase_approval_partial_receiving_and_over_receiving(): void
    {
        $warehouse = $this->user('gudang');
        $ph = $this->user('apoteker_pj');
        $item = $this->receipt('UNUSED')['items'][0];
        $d = ['supplier_id' => 1, 'idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $item['product_id'], 'unit_id' => $item['unit_id'], 'quantity' => '2']]];
        $id = Operations::purchase($d, $warehouse);
        $this->assertSame($id, Operations::purchase($d, $warehouse));
        $this->actingAs($warehouse)->postJson('/actions/receipt', $this->receipt('DRAFT', $id))->assertUnprocessable();
        $this->actingAs($warehouse)->postJson('/actions/purchase.approve', ['id' => $id])->assertForbidden();
        Operations::approvePurchase($id, $ph);
        $this->actingAs($warehouse)->get('/documents/purchases/'.$id)->assertOk();
        foreach (['PART1', 'PART2'] as $index => $invoice) {
            $this->actingAs($warehouse)->post('/actions/receipt', $this->receipt($invoice, $id))->assertSessionHasNoErrors();
            $r = DB::table('receipts')->where('invoice', $invoice)->first();
            $this->assertDatabaseHas('receipt_items', ['receipt_id' => $r->id, 'quantity_base' => '100.0000', 'quantity' => '1.0000']);
            Inventory::verifyReceipt($r->id, $ph);
            $this->assertDatabaseHas('purchase_orders', ['id' => $id, 'status' => $index ? 'received' : 'partial']);
        }
        $this->actingAs($warehouse)->postJson('/actions/receipt', $this->receipt('EXCESS', $id))->assertUnprocessable();
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertEquals('400.0000', Inventory::eligible($item['product_id'])->sum('l.quantity'));
    }

    public function test_receiving_requires_saved_checks_and_valid_supplier_documents(): void
    {
        $warehouse = $this->user('gudang');
        $ph = $this->user('apoteker_pj');
        $this->actingAs($warehouse)->post('/actions/receipt', $this->receipt('CHECKLIST', null, false))->assertSessionHasNoErrors();
        $id = DB::table('receipts')->where('invoice', 'CHECKLIST')->value('id');
        $this->actingAs($ph)->postJson('/actions/receipt.verify', ['id' => $id])->assertUnprocessable();
        $this->actingAs($warehouse)->get('/documents/receipts/'.$id)->assertOk();
        $d = ['id' => $id, 'checklist' => ['packaging' => true, 'documents' => true, 'quantity' => true], 'reason' => 'Sudah diperiksa fisik'];
        $this->actingAs($warehouse)->post('/actions/receipt.checklist', $d)->assertSessionHasNoErrors();
        Inventory::verifyReceipt($id, $ph);
        $this->actingAs($warehouse)->postJson('/actions/receipt.checklist', $d)->assertUnprocessable();
        DB::table('suppliers')->where('id', 1)->update(['verified' => false, 'license_expires' => now()->subDay()->toDateString()]);
        $this->actingAs($ph)->postJson('/actions/supplier.verify', ['id' => 1])->assertUnprocessable();
        $this->assertFalse(DB::table('suppliers')->find(1)->verified);
    }

    public function test_opname_detects_intervening_movements_even_when_balance_is_unchanged(): void
    {
        $warehouse = $this->user('gudang');
        $ph = $this->user('apoteker_pj');
        $id = Operations::startCount(1, $warehouse);
        $this->actingAs($warehouse)->postJson('/actions/count', ['layer_id' => 1])->assertUnprocessable();
        $this->actingAs($ph)->postJson('/actions/count.approve', ['id' => $id])->assertUnprocessable();
        $d = ['id' => $id, 'counted' => '195', 'reason' => 'Hitungan fisik'];
        $this->actingAs($this->user('ttk'))->postJson('/actions/count.submit', $d)->assertForbidden();
        Operations::submitCount($d, $warehouse);
        Inventory::move(1, '-1', 'test', 'test', 1, $ph, 'Uji keluar');
        Inventory::move(1, '1', 'test', 'test', 2, $ph, 'Uji masuk');
        $this->assertEquals('200.0000', DB::table('inventory_layers')->find(1)->quantity);
        $this->actingAs($ph)->postJson('/actions/count.approve', ['id' => $id])->assertUnprocessable();
        Operations::recount($id, $warehouse);
        Operations::submitCount($d, $warehouse);
        Inventory::approveCount($id, $ph);
        $this->assertEquals('195.0000', DB::table('inventory_layers')->find(1)->quantity);
        $this->actingAs($warehouse)->postJson('/actions/count.recount', ['id' => $id])->assertUnprocessable();
    }

    public function test_supplier_payments_are_separate_idempotent_and_cannot_exceed_debt(): void
    {
        $warehouse = $this->user('gudang');
        $owner = $this->user('pemilik');
        $this->actingAs($warehouse)->post('/actions/receipt', $this->receipt('PAYMENT'))->assertSessionHasNoErrors();
        $id = DB::table('receipts')->where('invoice', 'PAYMENT')->value('id');
        $d = ['receipt_id' => $id, 'amount' => '6000', 'reference' => 'BANK-UJI', 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($owner)->postJson('/actions/supplier.pay', $d)->assertUnprocessable();
        Inventory::verifyReceipt($id, $this->user('apoteker_pj'));
        $this->actingAs($warehouse)->postJson('/actions/supplier.pay', $d)->assertForbidden();
        $payment = Operations::supplierPay($d, $owner);
        $this->assertSame($payment, Operations::supplierPay($d, $owner));
        $d['amount'] = '4000';
        $this->actingAs($owner)->postJson('/actions/supplier.pay', $d)->assertUnprocessable();
        $d['idempotency_key'] = (string) Str::uuid();
        Operations::supplierPay($d, $owner);
        $d['idempotency_key'] = (string) Str::uuid();
        $d['amount'] = '1';
        $this->actingAs($owner)->postJson('/actions/supplier.pay', $d)->assertUnprocessable();
        $this->assertEquals('10000.00', DB::table('supplier_payments')->sum('amount'));
        $this->assertDatabaseCount('supplier_payments', 2);
    }

    public function test_cash_movement_and_shift_review_follow_the_cashier_owner_roles(): void
    {
        $cashier = $this->user('kasir');
        $owner = $this->user('pemilik');
        $this->actingAs($cashier)->post('/actions/shift.open', ['opening_cash' => '100000'])->assertSessionHasNoErrors();
        $id = DB::table('shifts')->value('id');
        $this->actingAs($owner)->postJson('/actions/shift.approve', ['id' => $id])->assertUnprocessable();
        $d = ['idempotency_key' => (string) Str::uuid(), 'amount' => '-10000', 'reason' => 'Setoran kas'];
        $movement = Operations::cash($d, $cashier);
        $this->assertSame($movement, Operations::cash($d, $cashier));
        $d['amount'] = '-20000';
        $this->actingAs($cashier)->postJson('/actions/cash', $d)->assertUnprocessable();
        $other = User::factory()->create(['role' => 'kasir']);
        $this->actingAs($other)->postJson('/actions/shift.close', ['id' => $id, 'counted_cash' => '90000', 'note' => 'Tidak boleh'])->assertForbidden();
        $close = ['id' => $id, 'counted_cash' => '90000', 'note' => 'Kas cocok'];
        $this->actingAs($cashier)->post('/actions/shift.close', $close)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('shifts', ['id' => $id, 'expected_cash' => '90000.00', 'difference' => '0.00']);
        $this->actingAs($owner)->post('/actions/shift.approve', ['id' => $id])->assertSessionHasNoErrors();
        $d['idempotency_key'] = (string) Str::uuid();
        $this->actingAs($cashier)->postJson('/actions/cash', $d)->assertUnprocessable();
        $close['counted_cash'] = '1';
        $this->actingAs($cashier)->postJson('/actions/shift.close', $close)->assertUnprocessable();
    }

    public function test_each_module_search_filters_records(): void
    {
        $pages = ['locations', 'purchases', 'receipts', 'stock', 'ledger', 'counts', 'sales', 'shifts', 'temperatures', 'finance', 'returns', 'audit', 'users'];
        foreach ($pages as $page) {
            $role = $page === 'users' ? 'admin' : 'auditor';
            $this->actingAs($this->user($role))->get('/'.$page.'?q=NO-SUCH-RECORD-2026')->assertOk()->assertInertia(fn ($page) => $page->where('rows.total', 0));
        }
        $this->actingAs($this->user('admin'))->get('/users?q=kasir')->assertInertia(fn ($page) => $page->where('rows.total', 1));
    }

    public function test_cashier_lists_and_dashboard_do_not_expose_other_cashiers_sales(): void
    {
        $cashier = $this->user('kasir');
        $other = User::factory()->create(['role' => 'kasir']);
        foreach ([$cashier, $other] as $index => $user) {
            $shift = DB::table('shifts')->insertGetId(['user_id' => $user->id, 'opening_cash' => '0', 'created_at' => now(), 'updated_at' => now()]);
            $sale = DB::table('sales')->insertGetId(['number' => 'SCOPE-'.$index, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => 'test', 'user_id' => $user->id, 'shift_id' => $shift, 'total' => '1000', 'created_at' => now(), 'updated_at' => now()]);
            if ($index) {
                $foreignSale = $sale;
            }
        }
        $this->actingAs($cashier)->get('/sales')->assertInertia(fn ($page) => $page->where('rows.total', 1)->where('rows.data.0.number', 'SCOPE-0'));
        $this->get('/shifts')->assertInertia(fn ($page) => $page->where('rows.total', 1));
        $this->get('/')->assertInertia(fn ($page) => $page->has('extra.recent', 1)->where('extra.recent.0.number', 'SCOPE-0'));
        $this->get('/sales/'.$foreignSale)->assertForbidden();
        $this->actingAs($this->user('gudang'))->get('/')->assertInertia(fn ($page) => $page->has('extra.recent', 0)->missing('extra.metrics'));
    }

    public function test_pending_receipt_cannot_be_counted_and_unconfirmed_payment_blocks_closing(): void
    {
        $warehouse = $this->user('gudang');
        $this->actingAs($warehouse)->post('/actions/receipt', $this->receipt('PENDING'))->assertSessionHasNoErrors();
        $receipt = DB::table('receipts')->where('invoice', 'PENDING')->value('id');
        $layer = DB::table('inventory_layers')->where('receipt_id', $receipt)->value('id');
        $this->postJson('/actions/count', ['layer_id' => $layer])->assertUnprocessable();
        $cashier = $this->user('kasir');
        $this->actingAs($cashier)->post('/actions/shift.open', ['opening_cash' => '0'])->assertSessionHasNoErrors();
        $shift = DB::table('shifts')->value('id');
        $sale = DB::table('sales')->insertGetId(['number' => 'UNCONFIRMED', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => 'test', 'user_id' => $cashier->id, 'shift_id' => $shift, 'total' => '1000', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['sale_id' => $sale, 'method' => 'qris', 'amount' => '1000', 'tendered' => '1000', 'verified' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/actions/shift.close', ['id' => $shift, 'counted_cash' => '0', 'note' => 'Belum selesai'])->assertUnprocessable();
        $this->assertNull(DB::table('shifts')->find($shift)->closed_at);
    }

    public function test_navigation_exposes_only_modules_the_account_can_open(): void
    {
        $this->actingAs($this->user('kasir'))->get('/')->assertInertia(fn ($page) => $page
            ->where('auth.modules', ['dashboard', 'products', 'pos', 'sales', 'shifts', 'returns'])
            ->where('auth.permissions', fn ($permissions) => ! in_array('finance', $permissions->all()) && ! in_array('pharmacy', $permissions->all())));
        $this->actingAs($this->user('auditor'))->get('/')->assertInertia(fn ($page) => $page
            ->where('auth.modules', fn ($modules) => in_array('finance', $modules->all()) && ! in_array('pos', $modules->all()) && ! in_array('users', $modules->all()))
            ->where('auth.permissions', fn ($permissions) => ! in_array('refund', $permissions->all()) && ! in_array('receive.verify', $permissions->all())));
    }

    public function test_expired_pharmacist_does_not_receive_clinical_action_permissions(): void
    {
        $pharmacist = $this->user('apoteker_pj');
        DB::table('users')->where('id', $pharmacist->id)->update(['credential_expires' => now()->subDay()->toDateString()]);
        $pharmacist->refresh();
        $this->actingAs($pharmacist)->get('/prescriptions')->assertInertia(fn ($page) => $page
            ->where('auth.permissions', fn ($permissions) => in_array('prescription.view', $permissions->all())
                && ! array_intersect(['pharmacy', 'prepare', 'receive.verify', 'stock.approve', 'purchase.approve', 'return.assess'], $permissions->all())));
        $this->postJson('/actions/purchase.approve', ['id' => 1])->assertForbidden();
        $this->postJson('/actions/prescription', [])->assertForbidden();
    }
}
