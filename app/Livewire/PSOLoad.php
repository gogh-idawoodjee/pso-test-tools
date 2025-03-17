<?php

namespace App\Livewire;

use App\Models\Environment;
use http\Env;
use Livewire\Component;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;

class PSOLoad extends Component implements HasForms
{

    use InteractsWithForms;

    public Environment $environment;

    public function mount(Environment $environment)
    {
        $this->environment = $environment;
    }

    public function render()
    {
        return view('livewire.p-s-o-load');
    }
}
