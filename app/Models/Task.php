<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Filament\Forms;

class Task extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'appt_window_finish' => 'datetime',
        'appt_window_start' => 'datetime',
    ];

    protected $guarded = [];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\Select::make('customer_id')
                ->relationship('customer', 'name')
                ->required(),
            Forms\Components\TextInput::make('name')
                ->required(),
            Forms\Components\TextInput::make('type')
                ->required(),
            Forms\Components\TextInput::make('status')
                ->required(),
            Forms\Components\TextInput::make('duration')
                ->required()
                ->numeric(),
            Forms\Components\DateTimePicker::make('appt_window_start')
                ->required(),
            Forms\Components\DateTimePicker::make('appt_window_finish')
                ->required(),
        ];
    }
}
