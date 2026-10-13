<?php
// Fixture sintetis untuk uji UI; hanya boleh dijalankan di database pengujian.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'apotek_test') throw new RuntimeException('Fixture hanya boleh memakai apotek_test.');
use Illuminate\Support\Facades\DB;
use App\Services\PrescriptionSearch;
$pharmacist = DB::table('users')->where('role', 'apoteker_pj')->value('id');
$product = DB::table('products')->where('sku', 'AMX-500')->value('id');
$id = DB::table('prescriptions')->insertGetId(['number'=>'RX-KASIR-UJI-SINTETIS','date'=>now()->toDateString(),'patient_name'=>encrypt('Pasien Uji Kasir Sintetis'),'doctor'=>'Dokter Uji Sintetis','notes'=>'DATA SINTETIS untuk uji perangkat lunak, bukan pemeriksaan klinis nyata.','items'=>json_encode([['product_id'=>$product,'quantity_base'=>'10']]),'status'=>'checked','created_by'=>$pharmacist,'reviewed_by'=>$pharmacist,'checked_by'=>$pharmacist,'review'=>'Persetujuan sintetis fixture pengujian UI.','created_at'=>now(),'updated_at'=>now()]);
PrescriptionSearch::sync($id,'Pasien Uji Kasir Sintetis','Dokter Uji Sintetis');
DB::table('shifts')->insert(['user_id'=>DB::table('users')->where('role','kasir')->value('id'),'opening_cash'=>'100000','created_at'=>now(),'updated_at'=>now()]);
echo "Fixture resep siap dan shift sintetis tersedia di apotek_test.\n";
