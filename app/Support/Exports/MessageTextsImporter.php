<?php

namespace App\Support\Exports;

use App\Models\PsoMessageText;
use Illuminate\Support\Facades\DB;
use XMLReader;

/** Fills the global message texts table from a PSO language export (xml, or a zip holding one xml). */
class MessageTextsImporter
{
    private const CHUNK_SIZE = 500;

    /** @return array{languages: list<string>, rows: int} */
    public function import(string $localPath): array
    {
        $path = ExportFormat::sniff($localPath) === 'zip'
            ? ExportFormat::largestMember($localPath)[0]
            : $localPath;

        $rowsByLanguage = $this->readRows($path);
        if ($rowsByLanguage === []) {
            throw new InvalidExportException('No Message_Text rows in this file.');
        }

        $languages = array_map('strval', array_keys($rowsByLanguage));
        sort($languages);
        $total = 0;
        $now = now();

        DB::transaction(static function () use ($rowsByLanguage, $languages, &$total, $now): void {
            PsoMessageText::query()->whereIn('language_id', $languages)->delete();

            foreach ($rowsByLanguage as $languageId => $textsByMessage) {
                $records = [];
                foreach ($textsByMessage as $messageId => $text) {
                    $records[] = [
                        'language_id' => (string) $languageId,
                        'message_id' => $messageId,
                        'text' => $text,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                foreach (array_chunk($records, self::CHUNK_SIZE) as $chunk) {
                    PsoMessageText::query()->insert($chunk);
                }
                $total += count($records);
            }
        });

        return ['languages' => $languages, 'rows' => $total];
    }

    /** @return array<string, array<int, string>> language id => [message id => text]; a repeated id keeps the last text */
    private function readRows(string $path): array
    {
        $reader = new XMLReader;
        if (! @$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new InvalidExportException('The file could not be read as XML. It may be damaged or incomplete.');
        }

        $rowsByLanguage = [];

        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1 || $reader->localName !== 'Message_Text') {
                    continue;
                }
                $node = $reader->expand();
                if ($node === false) {
                    continue;
                }

                $fields = [];
                foreach ($node->childNodes as $child) {
                    $fields[$child->localName ?? $child->nodeName] = trim($child->textContent);
                }

                $messageId = $fields['message_id'] ?? $fields['id'] ?? '';
                $text = $fields['text'] ?? $fields['message_text'] ?? '';
                if (! ctype_digit($messageId)) {
                    continue;
                }
                $languageId = ($fields['language_id'] ?? '') !== '' ? $fields['language_id'] : 'en';

                $rowsByLanguage[$languageId][(int) $messageId] = $text;
            }
        } finally {
            $reader->close();
        }

        return $rowsByLanguage;
    }
}
