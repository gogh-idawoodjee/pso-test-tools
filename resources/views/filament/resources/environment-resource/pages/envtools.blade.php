<x-filament-panels::page>

    {{ $this->sharedContextForm }}

    <div x-data="{ activeTab: 'load_rota' }" class="fi-sc-tabs fi-contained">
        <x-filament::tabs label="Environment Tools">
            <x-filament::tabs.item
                icon="heroicon-o-arrow-up-on-square"
                :alpine-active="'activeTab === \'load_rota\''"
                x-on:click="activeTab = 'load_rota'"
            >
                Initial Load and Rota
            </x-filament::tabs.item>

            <x-filament::tabs.item
                icon="heroicon-o-cog"
                :alpine-active="'activeTab === \'system_usage\''"
                x-on:click="activeTab = 'system_usage'"
            >
                System Usage
            </x-filament::tabs.item>

            <x-filament::tabs.item
                icon="heroicon-o-cog"
                :alpine-active="'activeTab === \'services\''"
                x-on:click="activeTab = 'services'"
            >
                Services
            </x-filament::tabs.item>

            <x-filament::tabs.item
                icon="heroicon-o-arrow-up-on-square"
                :alpine-active="'activeTab === \'gateway_upload\''"
                x-on:click="activeTab = 'gateway_upload'"
            >
                Load from File
            </x-filament::tabs.item>
        </x-filament::tabs>

        <div
            x-show="activeTab === 'load_rota'"
            x-cloak
            class="fi-sc-tabs-tab"
            x-bind:class="{ 'fi-active': activeTab === 'load_rota' }"
        >
            {{ $this->loadRotaForm }}
        </div>

        <div
            x-show="activeTab === 'system_usage'"
            x-cloak
            class="fi-sc-tabs-tab"
            x-bind:class="{ 'fi-active': activeTab === 'system_usage' }"
        >
            {{ $this->systemUsageForm }}
        </div>

        <div
            x-show="activeTab === 'services'"
            x-cloak
            class="fi-sc-tabs-tab"
            x-bind:class="{ 'fi-active': activeTab === 'services' }"
        >
            {{ $this->servicesForm }}
        </div>

        <div
            x-show="activeTab === 'gateway_upload'"
            x-cloak
            class="fi-sc-tabs-tab"
            x-bind:class="{ 'fi-active': activeTab === 'gateway_upload' }"
        >
            {{ $this->gatewayUploadForm }}
        </div>
    </div>

    <x-json-modal />

</x-filament-panels::page>
