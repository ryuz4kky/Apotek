<?php

use App\Jobs\BackupDatabase;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pharmacy:backup {--queue}', function () {
    $name = 'apotek-'.now()->format('Ymd-His').'.enc';
    if ($this->option('queue')) {
        BackupDatabase::dispatch($name);
        $this->info('Backup masuk antrean: '.$name);
    } else {
        (new BackupDatabase($name))->handle();
        $this->info('Backup terenkripsi tersimpan: storage/app/private/backups/'.$name);
    }
});
Artisan::command('pharmacy:restore-check {file}', function () {
    $file = $this->argument('file');
    if (! is_file($file)) {
        throw new RuntimeException('File tidak ditemukan.');
    }
    $dump = base64_decode(Crypt::decryptString(file_get_contents($file)), true);
    if ($dump === false) {
        throw new RuntimeException('Backup tidak valid.');
    }
    $c = config('database.connections.pgsql');
    $name = 'apotek_restore_'.bin2hex(random_bytes(4));
    $env = ['PGHOST' => $c['host'], 'PGPORT' => (string) $c['port'], 'PGUSER' => $c['username'], 'PGPASSWORD' => $c['password'], 'PGDATABASE' => 'postgres'];
    $tmp = tempnam(sys_get_temp_dir(), 'apotek-restore-');
    file_put_contents($tmp, $dump);
    chmod($tmp, 0600);
    try {
        (new Process(['createdb', $name], null, $env))->mustRun();
        (new Process(['pg_restore', '--no-owner', '--no-acl', '--dbname='.$name, $tmp], null, $env))->mustRun();
        $query = 'SELECT COALESCE(SUM(quantity),0)::text FROM inventory_layers; SELECT COALESCE(SUM(delta),0)::text FROM stock_ledger;';
        $p = new Process(['psql', '-d', $name, '-tA', '-c', $query], null, $env);
        $p->mustRun();
        $s = array_values(array_filter(explode("\n", trim($p->getOutput()))));
        if (count($s) !== 2 || bccomp($s[0], $s[1], 4) !== 0) {
            throw new RuntimeException('Saldo hasil restore tidak konsisten dengan ledger.');
        }$this->info('Restore ke database sementara berhasil. Saldo '.$s[0].' konsisten dengan ledger.');
    } finally {
        unlink($tmp);
        (new Process(['dropdb', '--if-exists', $name], null, $env))->run();
    }
});
