<?php

namespace App\Models;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Addon extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = true;

    /** حالة الإضافة تُقرأ مرّة لكل طلب (CustomHelper::isModuleEnabled): الكتابة تُسقطها. */
    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\RequestLookupCache::forget('addon:'));
        static::deleted(fn () => \App\Support\RequestLookupCache::forget('addon:'));
    }

    public function listAll($searchTerm)
    {
        return $this->where(function ($query) use ($searchTerm) {
                        $query->where('name', 'like', '%' . $searchTerm . '%');
                    })
                    ->oldest()
                    ->paginate(9);
    }
}
