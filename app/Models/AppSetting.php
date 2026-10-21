<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    public const CREATED_AT = null;

    protected $table = 'app_settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
