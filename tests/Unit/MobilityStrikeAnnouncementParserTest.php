<?php

use App\Services\Mobility\MobilityStrikeAnnouncementParser;

it('classifies official Italian strike updates without turning postponements into scheduled events', function (): void {
    $parser = new MobilityStrikeAnnouncementParser;

    $postponed = $parser->normalize(
        "Venerdì 30 non c'è sciopero sulle nostre linee",
        'Con ordinanza il Ministero ha rimandato lo sciopero generale nazionale per venerdì 30 ottobre.',
        '2026-10-26',
    );

    expect($postponed)->toMatchArray([
        'status' => 'postponed',
        'starts_at' => '2026-10-30',
        'ends_at' => '2026-10-30',
    ]);
});

it('does not treat an announcement publication date as an event date', function (): void {
    $parser = new MobilityStrikeAnnouncementParser;

    expect($parser->normalize(
        'Sciopero rinviato ad altra data',
        'Il Ministero ha rinviato lo sciopero ad altra data.',
        '2026-10-26',
    ))->toMatchArray([
        'status' => 'postponed',
        'starts_at' => null,
        'ends_at' => null,
    ]);
});

it('distinguishes scheduled, updated, cancelled, and unknown strike notices', function (): void {
    $parser = new MobilityStrikeAnnouncementParser;

    expect($parser->normalize('Sciopero nazionale confermato per il 30 ottobre 2026')['status'])->toBe('scheduled')
        ->and($parser->normalize('Aggiornamento: modifiche allo sciopero previsto il 30 ottobre 2026')['status'])->toBe('updated')
        ->and($parser->normalize('Sciopero revocato per il 30 ottobre 2026')['status'])->toBe('cancelled')
        ->and($parser->normalize('Informazioni sullo sciopero del 30 ottobre 2026')['status'])->toBe('unknown');
});

it('normalizes date ranges without confusing them with publication metadata', function (): void {
    $parser = new MobilityStrikeAnnouncementParser;

    expect($parser->normalize('Sciopero nazionale del 29-30 ottobre 2026', '', '2026-10-28'))
        ->toMatchArray([
            'status' => 'scheduled',
            'starts_at' => '2026-10-29',
            'ends_at' => '2026-10-30',
        ]);
});
