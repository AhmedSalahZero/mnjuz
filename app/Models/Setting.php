<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];
    public $timestamps = false;

    public static function getValueByKey($key)
    {
        $setting = self::where('key', $key)->first();

        return $setting ? $setting->value : null;
    }
}
