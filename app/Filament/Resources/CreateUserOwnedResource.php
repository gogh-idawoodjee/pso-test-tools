<?php

namespace App\Filament\Resources;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Override;

abstract class CreateUserOwnedResource extends CreateRecord
{
    #[Override] protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Only add user_id if the model has a user relationship
        if (Auth::check()) {
            $data['user_id'] = Auth::id();
        }

        return $data;
    }
}
