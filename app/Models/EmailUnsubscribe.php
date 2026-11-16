<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** عنوان ألغى اشتراكه في بريد حملات منشأة. يُقرأ لحملات البريد وحدها. */
class EmailUnsubscribe extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public static function normalize(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    public static function record(int $organizationId, string $email): void
    {
        $email = self::normalize($email);

        if ($email === '') {
            return;
        }

        static::query()->insertOrIgnore([
            'organization_id' => $organizationId,
            'email' => $email,
            'created_at' => now(),
        ]);
    }

    public static function isUnsubscribed(int $organizationId, ?string $email): bool
    {
        return static::query()
            ->where('organization_id', $organizationId)
            ->where('email', self::normalize($email))
            ->exists();
    }
}
