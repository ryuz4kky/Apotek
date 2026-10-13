<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('products')->exists()) {
            return;
        }
        $password = env('DEMO_PASSWORD');
        if (! $password) {
            throw new \RuntimeException('Atur DEMO_PASSWORD sebelum menjalankan seed.');
        }
        foreach (config('pharmacy.roles') as $role => $permissions) {
            DB::table('users')->insert(['name' => match ($role) {
                'admin' => 'Administrator Contoh','kasir' => 'Dina · Kasir Contoh','apoteker_pj' => 'drs. Ratna · Apoteker Contoh','gudang' => 'Budi · Gudang Contoh',default => ucfirst($role).' Contoh'
            }, 'email' => $role.'@apotek.test', 'password' => Hash::make($password), 'role' => $role, 'credential_expires' => in_array($role, ['apoteker_pj', 'apoteker']) ? now()->addYear()->toDateString() : null, 'created_at' => now(), 'updated_at' => now()]);
        }
        $user = DB::table('users')->where('role', 'apoteker_pj')->value('id');
        $s = DB::table('suppliers')->insertGetId(['name' => 'PBF Nusantara (Data Contoh)', 'license' => 'CONTOH-BUKAN-IZIN-ASLI', 'license_expires' => now()->addYear()->toDateString(), 'verified' => true, 'address' => 'Makassar · alamat sintetis', 'created_at' => now(), 'updated_at' => now()]);
        $loc = DB::table('locations')->insertGetId(['name' => 'Rak A · Obat bebas', 'type' => 'rak', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('locations')->insert(['name' => 'Kulkas · Data Contoh', 'type' => 'kulkas', 'temperature_min' => '2', 'temperature_max' => '8', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('locations')->insert(['name' => 'Area karantina · Data contoh', 'type' => 'karantina', 'created_at' => now(), 'updated_at' => now()]);
        $receipt = DB::table('receipts')->insertGetId(['number' => 'TR-CONTOH-001', 'supplier_id' => $s, 'invoice' => 'FAKTUR-CONTOH-001', 'status' => 'posted', 'due_date' => now()->addDays(30)->toDateString(), 'checklist' => json_encode(['packaging' => true, 'documents' => true, 'quantity' => true]), 'total_cost' => '290500', 'created_by' => $user, 'verified_by' => $user, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([
            ['PAR-500', 'Paracetamol 500 mg', 'Paracetamol', 'obat_bebas', 'tablet', '500', '250', '200', '10'],
            ['VIT-C', 'Vitamin C 100 mg', 'Asam askorbat', 'suplemen', 'tablet', '1000', '450', '150', '20'],
            ['AMX-500', 'Amoxicillin 500 mg', 'Amoxicillin', 'obat_keras', 'kapsul', '1500', '750', '100', '10'],
            ['MASK-01', 'Masker medis 3 lapis', null, 'alkes', 'pcs', '2000', '1000', '50', '10'],
            ['ANT-01', 'Antasida tablet', 'Aluminium hidroksida', 'obat_bebas', 'tablet', '800', '400', '120', '20'],
        ] as $i => $p) {
            [$sku,$name,$generic,$cat,$base,$price,$cost,$qty,$min] = $p;
            $id = DB::table('products')->insertGetId(['sku' => $sku, 'barcode' => '89900000000'.$i, 'name' => $name, 'generic' => $generic, 'category' => $cat, 'base_unit' => $base, 'requires_prescription' => $cat === 'obat_keras', 'minimum_stock' => $min, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('product_units')->insert(['product_id' => $id, 'name' => $base, 'factor' => '1', 'sale_price' => $price]);
            if ($base !== 'pcs') {
                DB::table('product_units')->insert(['product_id' => $id, 'name' => 'strip', 'factor' => '10', 'sale_price' => bcmul($price, '10', 2)]);
                DB::table('product_units')->insert(['product_id' => $id, 'name' => 'dus', 'factor' => '100', 'sale_price' => bcmul($price, '100', 2)]);
            }$b = DB::table('batches')->insertGetId(['product_id' => $id, 'number' => 'CONTOH-'.($i + 1), 'expires_at' => now()->addMonths($i === 0 ? 2 : 18)->toDateString()]);
            $l = DB::table('inventory_layers')->insertGetId(['batch_id' => $b, 'location_id' => $loc, 'receipt_id' => $receipt, 'quantity' => $qty, 'received_quantity' => $qty, 'cost_per_base' => $cost, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('receipt_items')->insert(['receipt_id' => $receipt, 'product_id' => $id, 'unit_id' => DB::table('product_units')->where('product_id', $id)->where('name', $base)->value('id'), 'layer_id' => $l, 'quantity' => $qty, 'quantity_base' => $qty, 'unit_cost' => $cost]);
            DB::table('stock_ledger')->insert(['layer_id' => $l, 'delta' => $qty, 'balance_after' => $qty, 'kind' => 'opening', 'source_type' => 'receipt', 'source_id' => $receipt, 'user_id' => $user, 'reason' => 'Saldo awal sintetis · data contoh', 'created_at' => now()]);
        }
    }
}
