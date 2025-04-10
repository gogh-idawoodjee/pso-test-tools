<?php

namespace App\Filament\Pages;

use App\Classes\PSOObjectRegistry;
use App\Enums\HttpMethod;
use App\Models\Environment;
use App\Traits\FormTrait;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use JsonException;
use Override;

class GenerateCustomException extends Page

{
    use InteractsWithForms, FormTrait;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $activeNavigationIcon = 'heroicon-s-exclamation-triangle';

    protected static string $view = 'filament.pages.generate-custom-exception';
    protected static ?string $navigationGroup = 'Additional Tools';

}
