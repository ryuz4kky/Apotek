<?php

namespace App\Http\Middleware;

use App\Services\Access;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $r): array
    {
        return [...parent::share($r), 'auth' => function () use ($r) {
            if (! $r->user()) {
                return null;
            }
            $permissions = Access::availablePermissions($r->user());

            return ['user' => $r->user()->only('id', 'name', 'email', 'role'), 'permissions' => $permissions,
                'modules' => array_keys(array_filter(config('pharmacy.modules'), fn ($permission) => in_array($permission, $permissions)))];
        }, 'flash' => ['success' => fn () => $r->session()->get('success')], 'timezone' => config('pharmacy.timezone')];
    }
}
