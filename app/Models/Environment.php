<?php

namespace App\Models;

use App\Models\Scopes\UserOwnedModel;
use App\Rules\NoProdURL;
use Filament\Forms;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @method static Model|static create(array $attributes = [])
 * @method static Builder|static query()
 *
 * @mixin Builder
 */
class Environment extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'user_id' => 'integer',
    ];

    protected $fillable = [
        'id',
        'name',
        'slug',
        'account_id',
        'base_url',
        'description',
        'username',
        'password',
        'user_id',
    ];

    /**
     * Route-model binding will use slug instead of id
     */
    #[Override]
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    #[Override]
    protected static function booted(): void
    {

        static::addGlobalScope(new UserOwnedModel);
        static::creating(static function (self $env) {
            // TODO duplicated code
            if (empty($env->slug)) {
                $base = Str::slug($env->name);
                $slug = $base;
                $i = 1;
                while (static::where('slug', $slug)->exists()) {
                    $slug = "{$base}-{$i}";
                    $i++;
                }
                $env->slug = $slug;
            }
        });
    }

    public static function getForm(): array
    {

        return [
            Section::make('Setup')
                ->collapsible()
                ->columns()
                ->columnSpanFull()
                ->icon('heroicon-o-wrench-screwdriver')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required(),
                    Forms\Components\TextInput::make('base_url')
                        ->label('Base URL')
                        ->helperText('No Prod URLs allowed')
                        ->prefixIcon('heroicon-o-globe-alt')
                        ->rules(['url', new NoProdURL])
                        ->required(),

                    Forms\Components\TextInput::make('description')
                        ->required(),
                    Forms\Components\TextInput::make('account_id')
                        ->label('Account ID')
                        ->prefixIcon('heroicon-o-identification') // ID card icon
                        ->helperText('Typically Default for On Prem')
                        ->required(),
                    Fieldset::make('Credentials')
                        ->label('Credentials')
                        ->schema([Forms\Components\TextInput::make('username')
                            ->required()
                            ->prefixIcon('heroicon-o-user') // User icon
                            ->autocomplete(false),
                            Forms\Components\TextInput::make('password')
                                ->password()
                                ->prefixIcon('heroicon-o-lock-closed') // Lock icon
                                ->dehydrateStateUsing(fn (string $state): string => Crypt::encryptString($state))
                                ->dehydrated(static fn (?string $state): bool => filled($state))
                                ->autocomplete(false)
                                ->required(),
                        ])
                        ->columnSpanFull(),

                ]),

        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class)->chaperone();
    }

    #[Override]
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
