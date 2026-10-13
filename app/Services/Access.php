<?php

namespace App\Services;

use App\Models\User;

class Access
{
    private const CLINICAL_PERMISSIONS = ['pharmacy', 'receive.verify', 'stock.approve', 'purchase.approve', 'return.assess'];

    public static function availablePermissions(User $u): array
    {
        if (! $u->active) {
            return [];
        }

        $permissions = self::permissions($u);
        $pharmacist = in_array($u->role, ['apoteker_pj', 'apoteker']);
        $credentialValid = $pharmacist && $u->credential_expires && $u->credential_expires >= now()->timezone(config('pharmacy.timezone'))->toDateString();
        if (! $credentialValid) {
            $permissions = array_diff($permissions, self::CLINICAL_PERMISSIONS);
            if ($pharmacist) {
                $permissions = array_diff($permissions, ['prepare']);
            }
        }

        return array_values($permissions);
    }

    public static function permissions(User $u): array
    {
        return array_values(array_unique(array_merge(config('pharmacy.roles.'.$u->role, []), json_decode($u->getRawOriginal('permissions') ?? '[]', true) ?? [])));
    }

    public static function check(User $u, string $permission): void
    {
        abort_unless($u->active && in_array($permission, self::permissions($u)), 403, 'Anda tidak memiliki izin untuk tindakan ini.');
        if (in_array($permission, self::CLINICAL_PERMISSIONS)) {
            abort_unless(in_array($u->role, ['apoteker_pj', 'apoteker']) && $u->credential_expires && $u->credential_expires >= now()->timezone(config('pharmacy.timezone'))->toDateString(), 403, 'Kewenangan apoteker tidak tersedia atau telah kedaluwarsa.');
        }
    }
}
