<?php

namespace App\Filament\Pages;

use App\Traits\AdminViewable;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use App\Models\User;

class IssueToken extends Page
{

    use AdminViewable;

    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static string $view = 'filament.pages.issue-token';

    public ?string $token = null;

    public ?array $formData = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('formData.user_id')
                    ->label('User')
                    ->options(User::pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                Forms\Components\TextInput::make('formData.token_name')
                    ->label('Token Name')
                    ->default('UI Issued Token')
                    ->required(),

                Forms\Components\TagsInput::make('formData.abilities')
                    ->label('Abilities (optional)')
                    ->placeholder('e.g. read,write')
                    ->helperText('Leave empty for ["*"]'),
            ]);
    }

    public function issue(): void
    {
        $data = $this->formData;

        $user = User::findOrFail($data['user_id']);

        $this->token = $user->createToken(
            $data['token_name'],
            $data['abilities'] ?? ['*'],
            Carbon::now()->addMonths(6)
        )->plainTextToken;

        Notification::make()
            ->title('Token created')
            ->success()
            ->send();
    }
}
