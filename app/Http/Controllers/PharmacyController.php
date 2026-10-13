<?php

namespace App\Http\Controllers;

use App\Services\Access;
use App\Services\Inventory;
use App\Services\Operations;
use App\Services\PrescriptionSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PharmacyController extends Controller
{
    public function page(Request $r, string $module = 'dashboard')
    {
        abort_unless(array_key_exists($module, config('pharmacy.modules')), 404);
        $u = $r->user();
        Access::check($u, config('pharmacy.modules.'.$module));
        $q = $r->string('q')->toString();
        $permissions = Access::permissions($u);
        $cost = in_array('cost', $permissions);
        $rows = null;
        $extra = [];
        if (in_array($module, ['products', 'pos'])) {
            $query = DB::table('products')->where('active', true)->when($q, fn ($b) => $b->where(fn ($b) => $b->where('name', 'ilike', '%'.$q.'%')->orWhere('sku', 'ilike', '%'.$q.'%')->orWhere('generic', 'ilike', '%'.$q.'%')->orWhere('barcode', $q)));
            $rows = $query->orderBy('name')->paginate(15)->withQueryString();
            $rows->through(function ($p) {
                $p->units = DB::table('product_units')->where('product_id', $p->id)->orderBy('factor')->get();
                $p->available = Inventory::eligible($p->id)->sum('l.quantity');

                return $p;
            });
        }
        if ($module === 'suppliers') {
            $rows = DB::table('suppliers')->when($q, fn ($b) => $b->where('name', 'ilike', '%'.$q.'%'))->orderByDesc('id')->paginate(20);
        }
        if ($module === 'locations') {
            $rows = DB::table('locations')->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('name', 'ilike', '%'.$q.'%')->orWhere('type', 'ilike', '%'.$q.'%')))->orderBy('name')->paginate(20);
        }
        if ($module === 'purchases') {
            $rows = DB::table('purchase_orders as o')->join('suppliers as s', 's.id', '=', 'o.supplier_id')->select('o.*', 's.name as supplier')->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('o.number', 'ilike', '%'.$q.'%')->orWhere('s.name', 'ilike', '%'.$q.'%')))->orderByDesc('o.id')->paginate(20);
        }
        if ($module === 'purchases') {
            $extra['suppliers'] = DB::table('suppliers')->select('id', 'name')->orderBy('name')->get();
        }
        if ($module === 'receipts') {
            $rows = DB::table('receipts as r')->join('suppliers as s', 's.id', '=', 'r.supplier_id')->select('r.id', 'r.purchase_order_id', 'r.number', 'r.invoice', 'r.status', 'r.due_date', 's.name as supplier', ...($cost ? ['r.total_cost'] : []))->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('r.number', 'ilike', '%'.$q.'%')->orWhere('r.invoice', 'ilike', '%'.$q.'%')->orWhere('s.name', 'ilike', '%'.$q.'%')))->orderByDesc('r.id')->paginate(20);
            $extra['suppliers'] = DB::table('suppliers')->select('id', 'name')->orderBy('name')->get();
            $extra['locations'] = DB::table('locations')->select('id', 'name')->get();
            $extra['orders'] = DB::table('purchase_orders')->whereIn('status', ['approved', 'partial'])->select('id', 'number', 'supplier_id')->orderByDesc('id')->limit(100)->get();
        }
        if ($module === 'stock') {
            $rows = DB::table('inventory_layers as l')->join('batches as b', 'b.id', '=', 'l.batch_id')->join('products as p', 'p.id', '=', 'b.product_id')->join('locations as loc', 'loc.id', '=', 'l.location_id')->select('l.id', 'p.name', 'p.base_unit', 'b.number as batch', 'b.expires_at', 'b.recalled', 'loc.name as location', 'l.status', 'l.quantity', ...($cost ? ['l.cost_per_base'] : []))->when($q, fn ($b) => $b->where('p.name', 'ilike', '%'.$q.'%'))->orderBy('b.expires_at')->paginate(20);
        }
        if ($module === 'ledger') {
            $rows = DB::table('stock_ledger as s')->join('inventory_layers as l', 'l.id', '=', 's.layer_id')->join('batches as b', 'b.id', '=', 'l.batch_id')->join('products as p', 'p.id', '=', 'b.product_id')->join('users as u', 'u.id', '=', 's.user_id')->select('s.*', 'p.name', 'b.number as batch', 'u.name as user')->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('p.name', 'ilike', '%'.$q.'%')->orWhere('b.number', 'ilike', '%'.$q.'%')))->orderByDesc('s.id')->paginate(30);
        }
        if ($module === 'counts') {
            $rows = DB::table('stock_counts as c')->join('inventory_layers as l', 'l.id', '=', 'c.layer_id')->join('batches as b', 'b.id', '=', 'l.batch_id')->join('products as p', 'p.id', '=', 'b.product_id')->select('c.id', 'c.created_by', 'c.layer_id', 'c.counted', 'c.status', 'c.reason', 'p.name', 'b.number as batch', ...(in_array('stock.approve', $permissions) ? ['c.snapshot'] : []))->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('p.name', 'ilike', '%'.$q.'%')->orWhere('b.number', 'ilike', '%'.$q.'%')))->orderByDesc('c.id')->paginate(20);
        }
        if ($module === 'prescriptions') {
            $rows = DB::table('prescriptions')->select('id', 'number', 'date', 'patient_name', 'doctor', 'status')->when($q !== '', fn ($builder) => PrescriptionSearch::filter($builder, $q))->orderByDesc('id')->paginate(20)->through(function ($rx) {
                $rx->patient_name = decrypt($rx->patient_name);

                return $rx;
            });
        }
        if ($module === 'sales') {
            $rows = DB::table('sales as s')->join('users as u', 'u.id', '=', 's.user_id')->select('s.id', 's.number', 's.total', 's.payment_status', 's.delivery_status', 's.created_at', 'u.name as cashier')->when(in_array('sales', $permissions) && ! in_array('business.view', $permissions), fn ($b) => $b->where('s.user_id', $u->id))->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('s.number', 'ilike', '%'.$q.'%')->orWhere('u.name', 'ilike', '%'.$q.'%')))->orderByDesc('s.id')->paginate(20);
        }
        if ($module === 'shifts') {
            $rows = DB::table('shifts as s')->join('users as u', 'u.id', '=', 's.user_id')->select('s.*', 'u.name as cashier')->when(in_array('shift', $permissions) && ! in_array('business.view', $permissions), fn ($b) => $b->where('s.user_id', $u->id))->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('u.name', 'ilike', '%'.$q.'%')))->orderByDesc('s.id')->paginate(20);
        }
        if ($module === 'temperatures') {
            $rows = DB::table('temperatures as t')->join('locations as l', 'l.id', '=', 't.location_id')->select('t.*', 'l.name as location')->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('l.name', 'ilike', '%'.$q.'%')))->orderByDesc('t.id')->paginate(20);
            $extra['locations'] = DB::table('locations')->whereNotNull('temperature_min')->get();
        }
        if ($module === 'finance') {
            $rows = DB::table('receipts as r')->join('suppliers as s', 's.id', '=', 'r.supplier_id')->where('r.status', 'posted')->select('r.id', 'r.number', 's.name as supplier', 'r.total_cost', 'r.due_date')->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('r.number', 'ilike', '%'.$q.'%')->orWhere('s.name', 'ilike', '%'.$q.'%')))->orderBy('r.due_date')->paginate(20)->through(function ($row) {
                $row->paid = DB::table('supplier_payments')->where('receipt_id', $row->id)->sum('amount');
                $row->outstanding = bcsub($row->total_cost, (string) $row->paid, 2);

                return $row;
            });
        }
        if ($module === 'returns') {
            $rows = DB::table('customer_returns as r')->join('sale_items as i', 'i.id', '=', 'r.sale_item_id')->join('products as p', 'p.id', '=', 'i.product_id')->join('sales as s', 's.id', '=', 'r.sale_id')->leftJoin('refunds as f', 'f.customer_return_id', '=', 'r.id')->select('r.id', 'r.sale_id', 's.number', 'p.name', 'r.quantity_base', 'p.base_unit', 'r.reason', 'r.condition', 'r.assessment', 'r.assessment_note', 'f.id as refund_id', ...(in_array('finance.view', $permissions) ? ['r.amount', 'f.reference'] : []))->when($u->role === 'kasir', fn ($q) => $q->where('r.created_by', $u->id))->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('s.number', 'ilike', '%'.$q.'%')->orWhere('p.name', 'ilike', '%'.$q.'%')))->orderByDesc('r.id')->paginate(20)->through(function ($row) {
                $row->refund_state = $row->refund_id ? 'Sudah dicatat' : 'Belum dicatat';

                return $row;
            });
        }
        if ($module === 'audit') {
            $rows = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->select('a.*', 'u.name as user')->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('a.action', 'ilike', '%'.$q.'%')->orWhere('a.source_type', 'ilike', '%'.$q.'%')->orWhere('u.name', 'ilike', '%'.$q.'%')))->orderByDesc('a.id')->paginate(30);
        }
        if ($module === 'users') {
            $rows = DB::table('users')->select('id', 'name', 'email', 'role', 'active', 'credential_expires')->when($q !== '', fn ($b) => $b->where(fn ($match) => $match->where('name', 'ilike', '%'.$q.'%')->orWhere('email', 'ilike', '%'.$q.'%')))->orderBy('name')->paginate(20);
        }
        if ($module === 'dashboard') {
            $extra['metrics'] = ['pending' => DB::table('prescriptions')->whereNotIn('status', ['checked', 'rejected'])->count(), 'quarantine' => DB::table('inventory_layers')->where('status', 'quarantine')->sum('quantity'), 'expiring' => DB::table('inventory_layers as l')->join('batches as b', 'b.id', '=', 'l.batch_id')->where('l.quantity', '>', 0)->where('b.expires_at', '<=', now()->addDays(90)->toDateString())->count()];
            $extra['cards'] = [];
            if (in_array('business.view', $permissions) || in_array('sales', $permissions)) {
                $sales = DB::table('sales')->whereBetween('created_at', [now()->timezone(config('pharmacy.timezone'))->startOfDay()->utc(), now()->timezone(config('pharmacy.timezone'))->endOfDay()->utc()])->when(! in_array('business.view', $permissions), fn ($q) => $q->where('user_id', $u->id));
                $extra['metrics']['sales'] = (clone $sales)->sum('total');
                $extra['metrics']['transactions'] = (clone $sales)->count();
                $extra['cards'][] = ['label' => in_array('business.view', $permissions) ? 'Penjualan hari ini' : 'Penjualan Anda hari ini', 'value' => $extra['metrics']['sales'], 'money' => true, 'note' => $extra['metrics']['transactions'].' transaksi tercatat'];
                $extra['recent'] = DB::table('sales')->when(! in_array('business.view', $permissions), fn ($q) => $q->where('user_id', $u->id))->select('id', 'number', 'total', 'payment_status', 'delivery_status', 'created_at')->orderByDesc('id')->limit(6)->get();
            } else {
                $extra['recent'] = [];
            }
            if (in_array('prescription.view', $permissions)) {
                $extra['cards'][] = ['label' => 'Resep dalam proses', 'value' => $extra['metrics']['pending'], 'note' => 'Telaah, penyiapan, atau pemeriksaan'];
            }
            if (in_array('inventory.view', $permissions)) {
                $extra['cards'][] = ['label' => 'Mendekati kedaluwarsa', 'value' => $extra['metrics']['expiring'], 'note' => 'Lapisan stok dalam 90 hari'];
                $extra['cards'][] = ['label' => 'Stok karantina', 'value' => $extra['metrics']['quarantine'], 'note' => 'Tidak masuk stok layak jual'];
            }
            if (in_array('shift', $permissions)) {
                $extra['cards'][] = ['label' => 'Shift aktif Anda', 'value' => DB::table('shifts')->where('user_id', $u->id)->whereNull('closed_at')->count(), 'note' => 'Buka/tutup dan hitung kas fisik'];
            }
            if (in_array('return.view', $permissions)) {
                $extra['cards'][] = ['label' => 'Retur menunggu penilaian', 'value' => DB::table('customer_returns')->where('assessment', 'pending')->when($u->role === 'kasir', fn ($q) => $q->where('created_by', $u->id))->count(), 'note' => 'Penerimaan barang dan refund terpisah'];
            }
            if (in_array('purchase.view', $permissions)) {
                $extra['cards'][] = ['label' => 'Pesanan dalam proses', 'value' => DB::table('purchase_orders')->whereIn('status', ['draft', 'approved', 'partial'])->count(), 'note' => 'Persetujuan dan penerimaan sebagian'];
            }
            if (in_array('users', $permissions)) {
                $extra['cards'][] = ['label' => 'Pengguna aktif', 'value' => DB::table('users')->where('active', true)->count(), 'note' => 'Pengelolaan akun dan peran'];
            }
            $extra['cards'] = array_slice($extra['cards'], 0, 4);
            unset($extra['metrics']);
        }
        if ($module === 'pos') {
            $extra['shift'] = DB::table('shifts')->where('user_id', $u->id)->whereNull('closed_at')->first();
        }

        if ($rows) {
            $rows->withQueryString();
        }

        return Inertia::render('Workspace', ['module' => $module, 'rows' => $rows, 'q' => $q, 'extra' => $extra]);
    }

    public function lookup(Request $r)
    {
        Access::check($r->user(), 'view');

        return DB::table('products')->where('active', true)->where(fn ($b) => $b->where('name', 'ilike', '%'.$r->string('q').'%')->orWhere('sku', 'ilike', '%'.$r->string('q').'%'))->limit(15)->get()->map(function ($p) {
            $p->units = DB::table('product_units')->where('product_id', $p->id)->orderBy('factor')->get();

            return $p;
        });
    }

    public function lookupPrescriptions(Request $r)
    {
        Access::check($r->user(), 'sales');
        $query = $r->validate(['q' => 'nullable|string|max:100'])['q'] ?? '';

        $results = DB::table('prescriptions')->select('id', 'number', 'date', 'status', 'items', 'patient_name', 'doctor', 'reviewed_by', 'checked_by')
            ->whereIn('status', ['received', 'reviewed', 'prepared', 'checked'])
            ->when($query !== '', fn ($builder) => PrescriptionSearch::filter($builder, $query))
            ->orderByDesc('id')->limit(15)->get()->map(function ($rx) {
                $rx->items = collect(json_decode($rx->items, true))->map(function ($item) use ($rx) {
                    $product = DB::table('products')->find($item['product_id']);
                    if (! $product) {
                        return null;
                    }
                    $billed = DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')
                        ->where('s.prescription_id', $rx->id)->where('i.product_id', $product->id)->sum('i.quantity_base');

                    return ['product_id' => $product->id, 'name' => $product->name, 'base_unit' => $product->base_unit,
                        'quantity_base' => $item['quantity_base'], 'remaining_base' => bcsub((string) $item['quantity_base'], (string) $billed, 4),
                        'available' => (string) Inventory::eligible($product->id)->sum('l.quantity'),
                        'special_control' => $product->special_control, 'active' => $product->active,
                        'units' => DB::table('product_units')->where('product_id', $product->id)->orderBy('factor')->get()];
                })->filter()->values();
                $rx->patient_name = decrypt($rx->patient_name);
                $rx->ready_for_checkout = Inventory::prescriptionReady($rx);
                unset($rx->reviewed_by, $rx->checked_by);

                return $rx;
            });

        return response()->json($results)->header('Cache-Control', 'no-store, private');
    }

    public function prescription(Request $r, int $id)
    {
        Access::check($r->user(), 'prescription.view');
        $rx = DB::table('prescriptions')->find($id);
        abort_unless($rx, 404);
        $rx->patient_name = decrypt($rx->patient_name);
        $rx->items = collect(json_decode($rx->items, true))->map(function ($item) {
            $p = DB::table('products')->find($item['product_id']);

            return [...$item, 'name' => $p?->name ?? 'Produk tidak ditemukan', 'base_unit' => $p?->base_unit];
        });

        return Inertia::render('Prescription', ['prescription' => $rx, 'events' => DB::table('prescription_events as e')->join('users as u', 'u.id', '=', 'e.user_id')->where('e.prescription_id', $id)->select('e.from_status', 'e.to_status', 'e.note', 'e.created_at', 'u.name as user')->orderBy('e.id')->get()]);
    }

    public function document(Request $r, string $kind, int $id)
    {
        abort_unless(in_array($kind, ['receipts', 'purchases']), 404);
        Access::check($r->user(), config('pharmacy.modules.'.$kind));
        $doc = DB::table($kind === 'receipts' ? 'receipts' : 'purchase_orders')->find($id);
        abort_unless($doc, 404);
        $supplier = DB::table('suppliers')->find($doc->supplier_id);
        if ($kind === 'receipts') {
            $items = DB::table('receipt_items as i')->join('products as p', 'p.id', '=', 'i.product_id')->join('product_units as u', 'u.id', '=', 'i.unit_id')->join('inventory_layers as l', 'l.id', '=', 'i.layer_id')->join('batches as b', 'b.id', '=', 'l.batch_id')->where('i.receipt_id', $id)->select('p.name', 'p.base_unit', 'u.name as unit', 'i.quantity', 'i.quantity_base', 'b.number as batch', 'b.expires_at', 'i.legacy_base_unit', ...(in_array('cost', Access::permissions($r->user())) ? ['i.unit_cost'] : []))->get();
            $doc->checklist = json_decode($doc->checklist, true);
            if (! in_array('cost', Access::permissions($r->user()))) {
                unset($doc->total_cost);
            }
        } else {
            $items = DB::table('purchase_order_items as i')->join('products as p', 'p.id', '=', 'i.product_id')->where('i.purchase_order_id', $id)->select('p.name', 'p.base_unit', 'i.product_id', 'i.quantity_base')->get()->map(function ($i) use ($id) {
                $i->received = DB::table('receipt_items as ri')->join('receipts as r', 'r.id', '=', 'ri.receipt_id')->where('r.purchase_order_id', $id)->where('r.status', 'posted')->where('ri.product_id', $i->product_id)->sum('ri.quantity_base');

                return $i;
            });
        }

        return Inertia::render('Document', ['kind' => $kind, 'document' => $doc, 'supplier' => $supplier, 'items' => $items]);
    }

    public function action(Request $r, string $action)
    {
        $u = $r->user();
        $id = null;
        $actionPermissions = ['product' => 'master', 'location' => 'master', 'user' => 'users', 'purchase' => 'purchase', 'purchase.approve' => 'purchase.approve', 'receipt' => 'receive', 'receipt.verify' => 'receive.verify', 'supplier.verify' => 'receive.verify', 'sale' => 'sales', 'sale.deliver' => 'sales.view', 'shift.open' => 'shift', 'shift.close' => 'shift', 'shift.approve' => 'shift.approve', 'cash' => 'shift', 'count' => 'stock.manage', 'count.submit' => 'stock.manage', 'count.recount' => 'stock.manage', 'count.approve' => 'stock.approve', 'prescription' => 'pharmacy', 'return' => 'return.intake', 'return.assess' => 'return.assess', 'refund' => 'refund', 'temperature' => 'stock.manage', 'supplier.pay' => 'finance'];
        if (isset($actionPermissions[$action])) {
            Access::check($u, $actionPermissions[$action]);
        }
        if ($action === 'prescription.advance' && ! array_intersect(['prepare', 'pharmacy'], Access::permissions($u))) {
            abort(403);
        }
        switch ($action) {
            case 'product':Access::check($u, 'master');
                $d = $r->validate(['sku' => 'required|string|max:80|unique:products', 'barcode' => 'nullable|string|max:100|unique:products', 'name' => 'required|string|max:255', 'generic' => 'nullable|string|max:255', 'base_unit' => 'required|string|max:40', 'category' => ['required', Rule::in(['obat_bebas', 'obat_bebas_terbatas', 'obat_keras', 'suplemen', 'alkes', 'narkotika', 'psikotropika', 'prekursor', 'oot'])], 'minimum_stock' => 'required|numeric|min:0', 'units' => 'required|array|min:1', 'units.*.name' => 'required|string|max:40|distinct', 'units.*.factor' => 'required|decimal:0,4|gt:0', 'units.*.sale_price' => 'required|decimal:0,2|min:0']);
                if (! collect($d['units'])->contains(fn ($a) => $a['name'] === $d['base_unit'] && bccomp($a['factor'], '1', 4) === 0)) {
                    Inventory::fail('Satuan dasar harus tercantum dengan faktor 1.');
                }
                $id = DB::transaction(function () use ($d) {
                    $units = $d['units'];
                    unset($d['units']);
                    $d['requires_prescription'] = $d['category'] === 'obat_keras' || in_array($d['category'], ['narkotika', 'psikotropika']);
                    $d['special_control'] = in_array($d['category'], ['narkotika', 'psikotropika', 'prekursor', 'oot']);
                    $id = DB::table('products')->insertGetId([...$d, 'created_at' => now(), 'updated_at' => now()]);
                    foreach ($units as $a) {
                        DB::table('product_units')->insert(['product_id' => $id, ...$a]);
                    }

                    return $id;
                });
                break;
            case 'supplier':if (! in_array('master', Access::permissions($u))) {
                Access::check($u, 'master.supplier');
            }
                $d = $r->validate(['name' => 'required|string|max:255', 'license' => 'required|string|max:255', 'license_expires' => 'required|date', 'contact' => 'nullable|string|max:255', 'address' => 'nullable|string|max:2000']);
                $id = DB::table('suppliers')->insertGetId([...$d, 'created_at' => now(), 'updated_at' => now()]);
                break;
            case 'supplier.verify':Access::check($u, 'receive.verify');
                $d = $r->validate(['id' => 'required|exists:suppliers,id']);
                $supplier = DB::table('suppliers')->find($d['id']);
                if (! $supplier->license || ! $supplier->license_expires || $supplier->license_expires < now()->timezone(config('pharmacy.timezone'))->toDateString()) {
                    Inventory::fail('Dokumen pemasok tidak lengkap atau kedaluwarsa.');
                }
                DB::table('suppliers')->where('id', $d['id'])->update(['verified' => true]);
                $id = $d['id'];
                break;
            case 'location':Access::check($u, 'master');
                $d = $r->validate(['name' => 'required|string|unique:locations', 'type' => 'required|string|max:40', 'temperature_min' => 'nullable|numeric', 'temperature_max' => 'nullable|numeric|gte:temperature_min']);
                $id = DB::table('locations')->insertGetId([...$d, 'created_at' => now(), 'updated_at' => now()]);
                break;
            case 'purchase':
                $d = $r->validate(['supplier_id' => 'required|exists:suppliers,id', 'idempotency_key' => 'required|uuid', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id|distinct', 'items.*.unit_id' => 'required|exists:product_units,id', 'items.*.quantity' => 'required|decimal:0,4|gt:0']);
                $id = Operations::purchase($d, $u);
                break;
            case 'purchase.approve':$id = $r->validate(['id' => 'required|exists:purchase_orders,id'])['id'];
                Operations::approvePurchase($id, $u);
                break;
            case 'receipt':Access::check($u, 'receive');
                $d = $r->validate(['purchase_order_id' => 'nullable|exists:purchase_orders,id', 'supplier_id' => 'required|exists:suppliers,id', 'invoice' => ['required', 'string', 'max:100', Rule::unique('receipts')->where('supplier_id', $r->input('supplier_id'))], 'due_date' => 'required|date', 'location_id' => 'required|exists:locations,id', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.unit_id' => 'required|exists:product_units,id', 'items.*.quantity' => 'required|decimal:0,4|gt:0', 'items.*.batch' => 'required|string|max:100', 'items.*.expires_at' => 'required|date|after:today', 'items.*.unit_cost' => 'required|decimal:0,2|min:0', 'checklist' => 'required|array', 'checklist.packaging' => 'required|boolean', 'checklist.documents' => 'required|boolean', 'checklist.quantity' => 'required|boolean']);
                $id = DB::transaction(function () use ($d, $u) {
                    $order = null;
                    if (! empty($d['purchase_order_id'])) {
                        $order = DB::table('purchase_orders')->where('id', $d['purchase_order_id'])->lockForUpdate()->first();
                        if (! in_array($order->status, ['approved', 'partial']) || $order->supplier_id != (int) $d['supplier_id']) {
                            Inventory::fail('Pesanan belum disetujui, sudah selesai, atau pemasok tidak sesuai.');
                        }
                    }
                    DB::table('products')->whereIn('id', collect($d['items'])->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
                    $id = DB::table('receipts')->insertGetId(['number' => 'TR-'.strtoupper(substr((string) Str::uuid(), 0, 12)), 'purchase_order_id' => $order?->id, 'supplier_id' => $d['supplier_id'], 'invoice' => $d['invoice'], 'due_date' => $d['due_date'], 'checklist' => json_encode($d['checklist']), 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
                    $total = '0';
                    foreach ($d['items'] as $i) {
                        $unit = DB::table('product_units')->where('product_id', $i['product_id'])->find($i['unit_id']);
                        if (! $unit) {
                            Inventory::fail('Satuan tidak sesuai produk.');
                        }
                        $b = DB::table('batches')->where(['product_id' => $i['product_id'], 'number' => $i['batch'], 'expires_at' => $i['expires_at']])->first();
                        $bid = $b?->id ?? DB::table('batches')->insertGetId(['product_id' => $i['product_id'], 'number' => $i['batch'], 'expires_at' => $i['expires_at']]);
                        $qty = bcmul($i['quantity'], $unit->factor, 4);
                        if ($order) {
                            $ordered = DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->where('product_id', $i['product_id'])->value('quantity_base');
                            $booked = DB::table('receipt_items as ri')->join('receipts as r', 'r.id', '=', 'ri.receipt_id')->where('r.purchase_order_id', $order->id)->where('ri.product_id', $i['product_id'])->sum('ri.quantity_base');
                            if ($ordered === null || bccomp(bcadd((string) $booked, $qty, 4), $ordered, 4) > 0) {
                                Inventory::fail('Jumlah penerimaan melebihi sisa pesanan produk ini.');
                            }
                        }
                        $layer = DB::table('inventory_layers')->insertGetId(['batch_id' => $bid, 'location_id' => $d['location_id'], 'receipt_id' => $id, 'quantity' => '0', 'received_quantity' => $qty, 'cost_per_base' => bcdiv($i['unit_cost'], $unit->factor, 6), 'created_at' => now(), 'updated_at' => now()]);
                        DB::table('receipt_items')->insert(['receipt_id' => $id, 'product_id' => $i['product_id'], 'unit_id' => $unit->id, 'layer_id' => $layer, 'quantity' => $i['quantity'], 'quantity_base' => $qty, 'unit_cost' => $i['unit_cost']]);
                        $total = bcadd($total, bcmul($i['quantity'], $i['unit_cost'], 2), 2);
                    }
                    DB::table('receipts')->where('id', $id)->update(['total_cost' => $total]);

                    return $id;
                });
                break;
            case 'receipt.checklist':
                $d = $r->validate(['id' => 'required|exists:receipts,id', 'checklist' => 'required|array', 'checklist.packaging' => 'required|boolean', 'checklist.documents' => 'required|boolean', 'checklist.quantity' => 'required|boolean', 'reason' => 'required|string|max:2000']);
                $id = $d['id'];
                if (in_array('receive.verify', Access::permissions($u))) {
                    Access::check($u, 'receive.verify');
                } else {
                    Access::check($u, 'receive');
                }
                DB::transaction(function () use ($d, $u, $id) {
                    $r = DB::table('receipts')->where('id', $id)->lockForUpdate()->first();
                    if ($r->status !== 'pending') {
                        Inventory::fail('Penerimaan yang sudah diposting tidak dapat diubah.');
                    }
                    if (! in_array('receive.verify', Access::permissions($u))) {
                        abort_unless($r->created_by === $u->id, 403);
                    }
                    DB::table('receipts')->where('id', $id)->update(['checklist' => json_encode($d['checklist']), 'updated_at' => now()]);
                    Inventory::audit($u, 'receipt.checklist', 'receipt', $id, ['reason' => $d['reason']]);
                });
                break;
            case 'receipt.verify':$id = $r->validate(['id' => 'required|exists:receipts,id'])['id'];
                Inventory::verifyReceipt($id, $u);
                break;
            case 'sale':$d = $r->validate(['idempotency_key' => 'required|uuid', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id|distinct', 'items.*.unit_id' => 'required|exists:product_units,id', 'items.*.quantity' => 'required|decimal:0,4|gt:0', 'payments' => 'required|array|min:1', 'payments.*.method' => ['required', Rule::in(['cash', 'transfer', 'card', 'qris'])], 'payments.*.amount' => 'required|decimal:0,2|min:0', 'payments.*.tendered' => 'nullable|decimal:0,2|min:0', 'payments.*.reference' => 'nullable|string|max:255', 'payments.*.received' => 'nullable|boolean', 'discount' => 'nullable|decimal:0,2|min:0', 'prescription_id' => 'nullable|exists:prescriptions,id', 'note' => 'nullable|string|max:2000']);
                $id = Inventory::checkout($d, $u);

                return redirect('/sales/'.$id)->with('success', 'Transaksi selesai. Pembayaran tercatat dan stok diperbarui.');
            case 'sale.deliver':$id = $r->validate(['id' => 'required|exists:sales,id'])['id'];
                Inventory::deliver($id, $u);
                break;
            case 'payment.verify':
                if (! in_array('finance', Access::permissions($u))) {
                    Access::check($u, 'sales');
                }
                $d = $r->validate(['id' => 'required|exists:sales,id', 'reference' => 'required|string|max:255']);
                $id = $d['id'];
                DB::transaction(function () use ($id, $d, $u) {
                    $sale = DB::table('sales')->where('id', $id)->lockForUpdate()->first();
                    if ($sale->payment_status === 'paid') {
                        Inventory::fail('Pembayaran sudah tercatat. Transaksi selesai tidak dapat diubah langsung.');
                    }
                    if (! in_array('finance', Access::permissions($u))) {
                        abort_unless($sale->user_id === $u->id, 403);
                    }
                    if ($sale->prescription_id) {
                        Inventory::requirePrescriptionReady(DB::table('prescriptions')->where('id', $sale->prescription_id)->lockForUpdate()->first());
                    }
                    DB::table('payments')->where('sale_id', $id)->update(['verified' => true, 'reference' => $d['reference']]);
                    DB::table('sales')->where('id', $id)->update(['payment_status' => 'paid']);
                    if (in_array('sales', Access::permissions($u))) {
                        Inventory::deliver($id, $u);
                    }
                });
                break;
            case 'shift.open':Access::check($u, 'shift');
                $d = $r->validate(['opening_cash' => 'required|decimal:0,2|min:0']);
                $id = DB::transaction(function () use ($u, $d) {
                    DB::table('users')->where('id', $u->id)->lockForUpdate()->first();
                    if (DB::table('shifts')->where('user_id', $u->id)->whereNull('closed_at')->exists()) {
                        Inventory::fail('Shift Anda masih terbuka.');
                    }

                    return DB::table('shifts')->insertGetId(['user_id' => $u->id, 'opening_cash' => $d['opening_cash'], 'created_at' => now(), 'updated_at' => now()]);
                });
                break;
            case 'shift.close':Access::check($u, 'shift');
                $d = $r->validate(['id' => 'required|exists:shifts,id', 'counted_cash' => 'required|decimal:0,2|min:0', 'note' => 'required|string|max:2000']);
                $id = $d['id'];
                DB::transaction(function () use ($u, $d, $id) {
                    $s = DB::table('shifts')->where('id', $id)->lockForUpdate()->first();
                    abort_unless($s->user_id === $u->id, 403);
                    if ($s->closed_at) {
                        if (bccomp($s->counted_cash, $d['counted_cash'], 2) === 0 && $s->note === $d['note']) {
                            return;
                        }
                        Inventory::fail('Shift sudah ditutup; hasil tidak dapat ditimpa.');
                    }
                    if (DB::table('payments as p')->join('sales as sale', 'sale.id', '=', 'p.sale_id')->where('sale.shift_id', $id)->where('p.verified', false)->exists()) {
                        Inventory::fail('Selesaikan konfirmasi pembayaran sebelum menutup shift.');
                    }
                    $cash = DB::table('payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')->where('s.shift_id', $id)->where('p.method', 'cash')->sum('p.amount');
                    $moves = DB::table('cash_movements')->where('shift_id', $id)->sum('amount');
                    $expected = bcadd(bcadd($s->opening_cash, (string) $cash, 2), (string) $moves, 2);
                    DB::table('shifts')->where('id', $id)->update(['closed_at' => now(), 'expected_cash' => $expected, 'counted_cash' => $d['counted_cash'], 'difference' => bcsub($d['counted_cash'], $expected, 2), 'note' => $d['note'], 'updated_at' => now()]);
                });
                break;
            case 'shift.approve':Access::check($u, 'shift.approve');
                $id = $r->validate(['id' => 'required|exists:shifts,id'])['id'];
                DB::transaction(function () use ($id, $u) {
                    $shift = DB::table('shifts')->where('id', $id)->lockForUpdate()->first();
                    if (! $shift->closed_at) {
                        Inventory::fail('Shift belum ditutup kasir.');
                    }
                    if ($shift->approved_by) {
                        Inventory::fail('Penutupan shift sudah ditinjau.');
                    }
                    DB::table('shifts')->where('id', $id)->update(['approved_by' => $u->id]);
                });
                break;
            case 'cash':$d = $r->validate(['idempotency_key' => 'required|uuid', 'amount' => 'required|decimal:0,2|not_in:0', 'reason' => 'required|string|max:2000']);
                $id = Operations::cash($d, $u);
                break;
            case 'count':$layer = $r->validate(['layer_id' => 'required|exists:inventory_layers,id'])['layer_id'];
                $id = Operations::startCount($layer, $u);
                break;
            case 'count.submit':$d = $r->validate(['id' => 'required|exists:stock_counts,id', 'counted' => 'required|decimal:0,4|min:0', 'reason' => 'required|string|max:2000']);
                $id = $d['id'];
                Operations::submitCount($d, $u);
                break;
            case 'count.recount':$id = $r->validate(['id' => 'required|exists:stock_counts,id'])['id'];
                Operations::recount($id, $u);
                break;
            case 'count.approve':$id = $r->validate(['id' => 'required|exists:stock_counts,id'])['id'];
                Inventory::approveCount($id, $u);
                break;
            case 'prescription':Access::check($u, 'pharmacy');
                $d = $r->validate(['number' => 'required|string|unique:prescriptions', 'date' => 'required|date', 'patient_name' => 'required|string|max:255', 'doctor' => 'required|string|max:255', 'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id|distinct', 'items.*.quantity_base' => 'required|decimal:0,4|gt:0'], [
                    'number.required' => 'Nomor resep wajib diisi.',
                    'number.unique' => 'Nomor resep sudah digunakan. Gunakan nomor resep yang berbeda.',
                    'date.required' => 'Tanggal resep wajib diisi.',
                    'date.date' => 'Tanggal resep tidak valid.',
                    'patient_name.required' => 'Nama pasien wajib diisi.',
                    'doctor.required' => 'Nama dokter wajib diisi.',
                    'items.required' => 'Tambahkan minimal satu obat ke daftar resep.',
                    'items.min' => 'Tambahkan minimal satu obat ke daftar resep.',
                    'items.*.product_id.distinct' => 'Obat yang sama tidak boleh ditambahkan dua kali. Gabungkan jumlahnya dalam satu item.',
                    'items.*.quantity_base.required' => 'Jumlah obat wajib diisi.',
                    'items.*.quantity_base.decimal' => 'Jumlah obat harus berupa angka dengan maksimal 4 angka desimal.',
                    'items.*.quantity_base.gt' => 'Jumlah obat harus lebih besar dari nol.',
                ]);
                $id = DB::transaction(function () use ($d, $u) {
                    $patient = $d['patient_name'];
                    $d['patient_name'] = encrypt($patient);
                    $d['items'] = json_encode($d['items']);
                    $id = DB::table('prescriptions')->insertGetId([...$d, 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
                    PrescriptionSearch::sync($id, $patient, $d['doctor']);
                    DB::table('prescription_events')->insert(['prescription_id' => $id, 'to_status' => 'received', 'note' => 'Resep dicatat.', 'user_id' => $u->id, 'created_at' => now()]);

                    return $id;
                });
                break;
            case 'prescription.advance':$d = $r->validate(['id' => 'required|exists:prescriptions,id', 'expected_status' => ['required', Rule::in(['received', 'reviewed', 'prepared'])], 'review' => 'required|string|max:4000']);
                $id = $d['id'];
                Operations::advancePrescription($d, $u);
                break;
            case 'return':$d = $r->validate(['sale_item_id' => 'required|exists:sale_items,id', 'idempotency_key' => 'required|uuid', 'quantity_base' => 'required|decimal:0,4|gt:0', 'location_id' => 'required|exists:locations,id', 'reason' => 'required|string|max:2000', 'condition' => 'required|string|max:2000']);
                $id = Operations::intakeReturn($d, $u);
                break;
            case 'return.assess':$d = $r->validate(['id' => 'required|exists:customer_returns,id', 'assessment' => ['required', Rule::in(['quarantine', 'unfit'])], 'assessment_note' => 'required|string|max:2000']);
                $id = $d['id'];
                Operations::assessReturn($d, $u);
                break;
            case 'refund':$d = $r->validate(['customer_return_id' => 'required|exists:customer_returns,id', 'idempotency_key' => 'required|uuid', 'reference' => 'required|string|max:255', 'reason' => 'required|string|max:2000']);
                $id = Operations::refund($d, $u);
                break;
            case 'temperature':Access::check($u, 'stock.manage');
                $d = $r->validate(['location_id' => 'required|exists:locations,id', 'value' => 'required|decimal:0,2', 'action' => 'nullable|string|max:2000']);
                $l = DB::table('locations')->find($d['location_id']);
                if ($l->temperature_min === null || $l->temperature_max === null) {
                    Inventory::fail('Rentang suhu lokasi belum dikonfigurasi.');
                }
                $deviation = bccomp($d['value'], $l->temperature_min, 2) < 0 || bccomp($d['value'], $l->temperature_max, 2) > 0;
                if ($deviation && empty($d['action'])) {
                    Inventory::fail('Penyimpangan suhu memerlukan tindakan koreksi.');
                }
                $id = DB::table('temperatures')->insertGetId([...$d, 'minimum' => $l->temperature_min, 'maximum' => $l->temperature_max, 'deviation' => $deviation, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
                break;
            case 'supplier.pay':$d = $r->validate(['receipt_id' => 'required|exists:receipts,id', 'idempotency_key' => 'required|uuid', 'amount' => 'required|decimal:0,2|gt:0', 'reference' => 'required|string|max:255']);
                $id = Operations::supplierPay($d, $u);
                break;
            case 'user':Access::check($u, 'users');
                $d = $r->validate(['name' => 'required|string|max:255', 'email' => 'required|email|unique:users', 'password' => 'required|string|min:12', 'role' => ['required', Rule::in(array_keys(config('pharmacy.roles')))], 'credential_expires' => 'nullable|date']);
                if (in_array($d['role'], ['apoteker', 'apoteker_pj'])) {
                    Inventory::fail('Akun apoteker harus diprovisikan setelah verifikasi dokumen kewenangan melalui pengelola sistem.');
                }
                $d['password'] = Hash::make($d['password']);
                $id = DB::table('users')->insertGetId([...$d, 'created_at' => now(), 'updated_at' => now()]);
                break;
            default:abort(404);
        }
        Inventory::audit($u, $action, $action, $id);

        return back()->with('success', 'Tindakan berhasil disimpan.');
    }

    public function sale(Request $r, int $id)
    {
        Access::check($r->user(), 'sales.view');
        $sale = DB::table('sales')->find($id);
        abort_unless($sale, 404);
        if ($r->user()->role === 'kasir') {
            abort_unless($sale->user_id === $r->user()->id, 403);
        }
        if (! in_array('cost', Access::permissions($r->user()))) {
            unset($sale->cost);
        }
        $items = DB::table('sale_items as i')->join('products as p', 'p.id', '=', 'i.product_id')->join('product_units as u', 'u.id', '=', 'i.unit_id')->where('sale_id', $id)->select('i.*', 'p.name', 'p.base_unit', 'u.name as unit')->get();

        $prescription = $sale->prescription_id ? DB::table('prescriptions')->find($sale->prescription_id) : null;
        $prescriptionSummary = $prescription ? ['number' => $prescription->number, 'date' => $prescription->date, 'status' => $prescription->status, 'ready_for_checkout' => Inventory::prescriptionReady($prescription)] : null;

        return Inertia::render('Sale', ['sale' => $sale, 'prescription' => $prescriptionSummary, 'items' => $items, 'quarantine_locations' => in_array('return.intake', Access::permissions($r->user())) ? DB::table('locations')->where('type', 'karantina')->get(['id', 'name']) : [], 'payments' => DB::table('payments')->where('sale_id', $id)->get(), 'allocations' => DB::table('sale_allocations as a')->join('sale_items as i', 'i.id', '=', 'a.sale_item_id')->join('inventory_layers as l', 'l.id', '=', 'a.layer_id')->join('batches as b', 'b.id', '=', 'l.batch_id')->where('i.sale_id', $id)->select('i.product_id', 'b.number', 'b.expires_at', 'a.quantity')->get()]);
    }
}
