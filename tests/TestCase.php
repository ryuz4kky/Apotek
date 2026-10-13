<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.connections.pgsql.database') !== 'apotek_test' || ! $app->environment('testing')) {
            throw new \RuntimeException('Tes wajib memakai APP_ENV=testing dan DB_DATABASE=apotek_test. Gunakan docker/test.sh.');
        }

        return $app;
    }
}
