<?php

// NOTE: If you create a Filament UserResource, override getUrl() to use $user->getHashid()
// See: app/Traits/Hashidable.php

namespace App\Models;

use App\Traits\Hashidable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Override;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Hashidable, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'last_tool_context',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_tool_context' => 'array',
        ];
    }

    /**
     * @param  string|null  $datasetId  null for environment-scoped tools
     * @param  bool  $unfiled  the last run was on the user's own Unfiled files
     * @param  string|null  $timeZone  the zone chosen for that Unfiled run
     */
    public function rememberToolContext(?string $environmentId, ?string $datasetId, bool $unfiled = false, ?string $timeZone = null): void
    {
        $this->forceFill(['last_tool_context' => [
            'environment_id' => $environmentId,
            'dataset_id' => $datasetId,
            'unfiled' => $unfiled,
            'time_zone' => $timeZone,
        ]])->save();
    }

    /** Unfiled exports: files that belong to no environment or dataset. */
    public function exportFiles(): MorphMany
    {
        return $this->morphMany(ExportFile::class, 'scope');
    }

    /** Unfiled runs. */
    public function toolRuns(): MorphMany
    {
        return $this->morphMany(ToolRun::class, 'scope');
    }

    #[Override]
    public function canAccessPanel(Panel $panel): bool
    {
        $allowed = str_ends_with($this->email, '@mac.com')
            || str_ends_with($this->email, '@goghsolutions.com')
            || str_ends_with($this->email, '@thetechnodro.me');

        if (! $allowed) {
            logger()->warning('Blocked Filament login attempt', ['email' => $this->email]);
        }

        return $allowed;
    }
}
