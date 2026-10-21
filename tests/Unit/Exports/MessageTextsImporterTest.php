<?php

use App\Models\PsoMessageText;
use App\Support\Exports\InvalidExportException;
use App\Support\Exports\MessageTextsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function messageTextsFile(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'msg_').'.xml';
    file_put_contents($path, '<?xml version="1.0"?><DsSystemData>'.$body.'</DsSystemData>');

    return $path;
}

function messageTextRow(int $messageId, string $languageId, string $text): string
{
    return "<Message_Text><message_id>{$messageId}</message_id><language_id>{$languageId}</language_id><text>{$text}</text></Message_Text>";
}

it('imports every language and maps english by message id', function () {
    $path = messageTextsFile(
        messageTextRow(2217, 'en', 'Broadcast to {0} returned status code {1}.')
        .messageTextRow(2879, 'en', 'Unexpected changes detected when merging clusters.')
        .messageTextRow(2217, 'fr', 'Diffusion terminee.')
    );

    expect(app(MessageTextsImporter::class)->import($path))->toBe(['languages' => ['en', 'fr'], 'rows' => 3])
        ->and(PsoMessageText::asMap())->toBe([
            '2217' => 'Broadcast to {0} returned status code {1}.',
            '2879' => 'Unexpected changes detected when merging clusters.',
        ]);
});

it('replaces only the languages in the new file', function () {
    app(MessageTextsImporter::class)->import(messageTextsFile(
        messageTextRow(1, 'en', 'Old one').messageTextRow(2, 'en', 'Two').messageTextRow(1, 'fr', 'Un')
    ));

    app(MessageTextsImporter::class)->import(messageTextsFile(
        messageTextRow(1, 'en', 'New one').messageTextRow(3, 'en', 'Three')
    ));

    expect(PsoMessageText::asMap())->toBe(['1' => 'New one', '3' => 'Three'])
        ->and(PsoMessageText::asMap('fr'))->toBe(['1' => 'Un'])
        ->and(PsoMessageText::count())->toBe(3);
});

it('reads the id and message_text child names too', function () {
    $path = messageTextsFile('<Message_Text><id>2217</id><message_text>Hello</message_text></Message_Text>');

    expect(app(MessageTextsImporter::class)->import($path))->toBe(['languages' => ['en'], 'rows' => 1])
        ->and(PsoMessageText::asMap())->toBe(['2217' => 'Hello']);
});

it('reads a zip holding one xml', function () {
    $zipPath = tempnam(sys_get_temp_dir(), 'msg_').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile(messageTextsFile(messageTextRow(5, 'en', 'Five')), 'texts.xml');
    $zip->close();

    expect(app(MessageTextsImporter::class)->import($zipPath)['rows'])->toBe(1);
});

it('refuses a file with no Message_Text rows', function () {
    app(MessageTextsImporter::class)->import(messageTextsFile(''));
})->throws(InvalidExportException::class, 'No Message_Text rows in this file.');
