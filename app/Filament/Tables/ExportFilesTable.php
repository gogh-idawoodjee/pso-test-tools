<?php

namespace App\Filament\Tables;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Tables;
use Filament\Tables\Table;

/** The exports table: a dataset's or environment's Exports tab, and My files. */
class ExportFilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('kind')->badge(),
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('File')
                    ->description(static fn (ExportFile $record): ?string => $record->error_message)
                    ->searchable(),
                Tables\Columns\TextColumn::make('summary.export_date')->label('Export date')->placeholder('-'),
                Tables\Columns\TextColumn::make('summary.input_reference_datetime')->label('Input reference time')->dateTime()->placeholder('-'),
                Tables\Columns\TextColumn::make('contents')
                    ->label('Contents')
                    ->state(static fn (ExportFile $record): ?string => self::contentsOf($record))
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('uploadedBy.name')->label('Uploaded by'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->tooltip(static fn (ExportFile $record): ?string => $record->error_message),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Raw file')
                    ->state(static fn (ExportFile $record): string => self::purgeCountdown($record)),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(static fn (ExportFile $record): string => route('exports.download', ['exportFile' => $record->id]))
                    ->visible(static fn (ExportFile $record): bool => $record->isDownloadable()),
                DeleteAction::make()
                    ->modalDescription('The file is removed from storage too. Results from runs that used it are kept.')
                    ->using(static function (ExportFile $record): void {
                        app(ExportStorage::class)->delete($record);
                        $record->delete();
                    }),
            ]);
    }

    private static function contentsOf(ExportFile $record): ?string
    {
        $summary = $record->summary ?? [];

        return match ($record->kind) {
            ExportKind::Sys => isset($summary['pso_version'])
                ? 'Version '.$summary['pso_version'].', '.number_format((int) ($summary['parameter_count'] ?? 0)).' parameters'
                : null,
            ExportKind::Audits => isset($summary['message_count'])
                ? number_format((int) $summary['message_count']).' messages, '.number_format((int) ($summary['appointment_request_count'] ?? 0)).' appointment requests'
                : null,
            ExportKind::EventLog => isset($summary['event_count'])
                ? number_format((int) $summary['event_count']).' events'.self::eventSpan($record, $summary)
                : null,
            default => isset($summary['allocation_count'])
                ? number_format((int) $summary['allocation_count']).' allocations'
                : (isset($summary['activity_count'])
                ? number_format((int) $summary['activity_count']).' activities, '.number_format((int) ($summary['resource_count'] ?? 0)).' resources'
                : null),
        };
    }

    /** @param array<string, mixed> $summary */
    private static function eventSpan(ExportFile $record, array $summary): string
    {
        if (! isset($summary['first_event'], $summary['last_event'])) {
            return '';
        }

        $zone = $record->scope instanceof Dataset ? $record->scope->timeZone() : (string) config('app.timezone', 'UTC');
        $format = static fn (string $time): string => CarbonImmutable::parse($time)->setTimezone($zone)->format('j M H:i');

        return ', '.$format($summary['first_event']).' to '.$format($summary['last_event']);
    }

    private static function purgeCountdown(ExportFile $record): string
    {
        if ($record->status === ExportFileStatus::Purged) {
            return 'Deleted from storage';
        }

        $minutes = (int) floor(now()->diffInMinutes($record->expires_at, false));

        if ($minutes <= 0) {
            return 'Purging soon';
        }

        return 'Purges in '.intdiv($minutes, 60).' h '.($minutes % 60).' m';
    }
}
