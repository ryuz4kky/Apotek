<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PrescriptionSearch
{
    public static function words(string $text): array
    {
        return array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY), fn ($word) => mb_strlen($word) >= 2)));
    }

    public static function token(string $word): string
    {
        return hash_hmac('sha256', $word, config('app.key'));
    }

    public static function filter($builder, string $query): void
    {
        $builder->where(function ($matches) use ($query) {
            $matches->where('number', 'ilike', '%'.$query.'%');
            $words = PrescriptionSearch::words($query);
            if ($words) {
                foreach (['patient', 'doctor'] as $scope) {
                    $matches->orWhere(function ($person) use ($words, $scope) {
                        foreach ($words as $word) {
                            $person->whereExists(function ($token) use ($word, $scope) {
                                $token->selectRaw('1')->from('prescription_search_tokens as t')->whereColumn('t.prescription_id', 'prescriptions.id')->where('t.scope', $scope)->where('t.token', PrescriptionSearch::token($word));
                            });
                        }
                    });
                }
            }
        });
    }

    public static function sync(int $id, string $patient, string $doctor): void
    {
        $rows = [];
        foreach (['patient' => $patient, 'doctor' => $doctor] as $scope => $text) {
            foreach (self::words($text) as $word) {
                for ($length = 2; $length <= min(mb_strlen($word), 100); $length++) {
                    $token = self::token(mb_substr($word, 0, $length));
                    $rows[$scope.$token] = ['prescription_id' => $id, 'scope' => $scope, 'token' => $token];
                }
            }
        }
        DB::table('prescription_search_tokens')->where('prescription_id', $id)->delete();
        if ($rows) {
            DB::table('prescription_search_tokens')->insert(array_values($rows));
        }
    }
}
