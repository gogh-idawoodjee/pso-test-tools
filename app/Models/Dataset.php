<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class Dataset extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'environment_id' => 'integer',
    ];

    protected $with = ['environment'];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function psoload()
    {
     Log::info('this method works');
    }
}
