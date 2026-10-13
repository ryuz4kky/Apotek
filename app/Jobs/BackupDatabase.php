<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Process\Process;

class BackupDatabase implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $filename) {}

    public function handle(): void
    {
        $c = config('database.connections.pgsql');
        $p = new Process(['pg_dump', '--format=custom', '--no-owner', '--no-acl'], null, ['PGHOST' => $c['host'], 'PGPORT' => (string) $c['port'], 'PGDATABASE' => $c['database'], 'PGUSER' => $c['username'], 'PGPASSWORD' => $c['password']]);
        $p->setTimeout(240);
        $p->mustRun();
        $path = storage_path('app/private/backups');
        if (! is_dir($path)) {
            mkdir($path, 0700, true);
        }$file = $path.'/'.basename($this->filename);
        file_put_contents($file, Crypt::encryptString(base64_encode($p->getOutput())));
        chmod($file, 0600);
    }
}
