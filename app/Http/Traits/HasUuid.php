<?php

namespace App\Http\Traits;

use Ramsey\Uuid\Uuid;

trait HasUuid
{

    protected static function bootHasUuid()
    {
        /**
         * Attach to the 'creating' Model Event to provide a UUID
         * for the `uuid` field
         */
        static::creating(function ($model) {
            $columnName = static::getUuidColumn();
            $model->$columnName = Uuid::uuid4();
        });
    }

    /* Getters and Setters */


    public function getUuidAttribute()
    {
        $columnName = static::getUuidColumn();

        return $this->attributes[$columnName];
    }

    protected static function getUuidColumn()
    {
        if (isset(static::$uuidColumn)) {
            return static::$uuidColumn;
        }
        return 'uuid';
    }

}
