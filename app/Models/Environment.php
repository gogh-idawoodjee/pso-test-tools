<?php

namespace App\Models;

use App\Rules\NoProdURL;
use Filament\Forms;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;


class Environment extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
//        'password',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'user_id' => 'integer',
    ];

    public static function getForm(): array
    {


        return [
            Forms\Components\Section::make('Setup')
                ->collapsible()
//                ->collapsed()
                ->columns()
                ->icon('heroicon-o-wrench-screwdriver')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required(),
                    Forms\Components\TextInput::make('base_url')
                        ->label('Base URL')
                        ->helperText('No Prod URLs allowed')
                        ->prefixIcon('heroicon-o-globe-alt')
//                        ->url()
                        ->rules(['url', new NoProdURL()])
                        ->required(),

                    Forms\Components\TextInput::make('description')
                        ->required(),
                    Forms\Components\TextInput::make('account_id')
                        ->label('Account ID')
                        ->helperText('Typically Default for On Prem')
                        ->required(),
                    Forms\Components\Fieldset::make('Credentials')
                        ->label('Credentials')
                        ->columns()
                        ->schema([Forms\Components\TextInput::make('username')
                            ->required()->autocomplete(false),
                            Forms\Components\TextInput::make('password')
                                ->password()
                                ->dehydrateStateUsing(fn(string $state): string => Hash::make($state))
                                ->dehydrated(fn(?string $state): bool => filled($state))
                                ->autocomplete(false)
                                ->required(),
                        ])


                ]),

        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class);
    }
}
