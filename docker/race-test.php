<?php
require __DIR__.'/../vendor/autoload.php';$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;use App\Models\User;use App\Services\Inventory;use Illuminate\Support\Str;
if(config('database.connections.pgsql.database')!=='apotek_test')throw new RuntimeException('Wajib database apotek_test.');
if(($argv[1]??'')==='child'){
 while(microtime(true)<(float)$argv[4])usleep(1000);
 $u=User::findOrFail((int)$argv[2]);$p=DB::table('products')->find((int)$argv[3]);$unit=DB::table('product_units')->where('product_id',$p->id)->first();try{Inventory::checkout(['idempotency_key'=>(string)Str::uuid(),'items'=>[['product_id'=>$p->id,'unit_id'=>$unit->id,'quantity'=>'1']],'payments'=>[['method'=>'cash','amount'=>'1000','tendered'=>'1000']],'discount'=>'0'],$u);echo 'SUCCESS';}catch(Illuminate\Validation\ValidationException $e){echo 'INSUFFICIENT';}exit;
}
Illuminate\Support\Facades\Artisan::call('migrate:fresh',['--force'=>true]);Illuminate\Support\Facades\Artisan::call('db:seed',['--force'=>true]);
$u=User::where('role','kasir')->first();$u2=User::create(['name'=>'Kasir Kedua Sintetis','email'=>'race@apotek.test','password'=>'TestOnlyPassword2026']);
foreach([$u,$u2] as $cashier)DB::table('shifts')->insert(['user_id'=>$cashier->id,'opening_cash'=>'0','created_at'=>now(),'updated_at'=>now()]);
$p=DB::table('products')->insertGetId(['sku'=>'RACE','name'=>'Produk tes stok terakhir','base_unit'=>'pcs','created_at'=>now(),'updated_at'=>now()]);DB::table('product_units')->insert(['product_id'=>$p,'name'=>'pcs','factor'=>'1','sale_price'=>'1000']);$b=DB::table('batches')->insertGetId(['product_id'=>$p,'number'=>'RACE','expires_at'=>now()->addYear()->toDateString()]);$l=DB::table('inventory_layers')->insertGetId(['batch_id'=>$b,'location_id'=>1,'quantity'=>'1','received_quantity'=>'1','cost_per_base'=>'500','status'=>'available','created_at'=>now(),'updated_at'=>now()]);
$start=microtime(true)+1; $children=[];foreach([$u,$u2] as $cashier){$proc=new Symfony\Component\Process\Process(['php',__FILE__,'child',(string)$cashier->id,(string)$p,(string)$start]);$proc->start();$children[]=$proc;}$results=[];foreach($children as $proc){$proc->wait();if(!$proc->isSuccessful())throw new RuntimeException($proc->getErrorOutput());$results[]=$proc->getOutput();}sort($results);
if($results!==['INSUFFICIENT','SUCCESS']||bccomp(DB::table('inventory_layers')->find($l)->quantity,'0',4)!==0||DB::table('sales')->count()!==1)throw new RuntimeException('Uji konkurensi gagal.');echo "PASS: dua proses kasir bersamaan, satu berhasil, satu ditolak; saldo 0, satu transaksi.\n";
