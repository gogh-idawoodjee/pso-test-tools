## Project Overview

FrontEnd for PSO-Services API and additional tools for PSO Testing.

## Stack

- Laravel 13
- Livewire 4
- Filament v5
- MySQL
- TailwindCSS v4
- PHP 8.5

## Conventions

- No abbreviated variable names — `$userRecord` not `$ur`
- Use `app()` over `new` for Filament/Laravel classes to allow binding overrides
- Never use `final` or `readonly` on classes that extend Filament
- `static fn` for closures that don't reference `$this`
- Constructor property promotion always
- Explicit return types and type hints on all methods

## Filament Namespaces

- Form fields: `Filament\Forms\Components\`
- Infolist entries: `Filament\Infolists\Components\`
- Layout components (Grid, Section, Tabs): `Filament\Schemas\Components\`
- Schema utilities (Get, Set): `Filament\Schemas\Components\Utilities\`
- Table columns: `Filament\Tables\Columns\`
- Table filters: `Filament\Tables\Filters\`
- Actions: `Filament\Actions\` — never sub-namespaces

## Common Mistakes to Avoid

- Never assume public file visibility — always `->visibility('public')` when needed
- Never assume full-width layout — use `->columnSpanFull()` explicitly
- `Repeater` uses `->schema()` not `->fields()`
- Never `->dehydrated(false)` on fields that need to be saved
- `$navigationIcon` type is `string | BackedEnum | null`, not `?string`
