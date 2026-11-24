<?php

use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\OksControlSchedule;

it('uses the updated wording without changing the layout or Telegram response actions', function (): void {
    $schedule = OksControlSchedule::questions('oks_schedule');
    expect($schedule['check']['details'])->toContain('актуальны ли заезды', 'заметки PM соответствуют');

    $start = OksControlSchedule::questions('oks_start_eleven');
    expect(array_keys($start))->toBe(['start'])
        ->and($start['start']['yes'])->toBe('✅ Все начались')
        ->and($start['start']['no'])->toBe('🔴 Не все')
        ->and($start['start']['details'])->toContain('EXTRA TIME', '30 минут');

    $linen = OksControlSchedule::questions('oks_linen');
    expect($linen['check']['yes'])->toBe('✅ Доставили')
        ->and($linen['check']['no'])->toBe('🔴 Не везде')
        ->and($linen['check']['skip'])->toBe('Нет приоритета по белью');

    $progress = OksControlSchedule::questions('oks_first_progress');
    expect($progress['check']['yes'])->toBe('✅ Да, график соблюдается')
        ->and($progress['check']['no'])->toBe('🔴 Нет, есть отставание')
        ->and($progress['check']['details'])->toContain('240 минут', 'влажной уборки поверхностей');

    $finish = OksControlSchedule::questions('oks_first_finish');
    expect(array_keys($finish))->toBe(['check'])
        ->and($finish['check']['title'])->toContain('все последующие уборки')
        ->and($finish['check']['yes'])->toBe('✅ Да, риска опозданий нет')
        ->and($finish['check']['no'])->toBe('🔴 Нет, меняем график');

    expect(OksControlSchedule::questions('oks_guest_arrivals')['check']['title'])
        ->toBe('Все уборки идут по графику и будут завершены к заезду гостей?');
});

it('keeps old two-question OKS deliveries readable while new deliveries use one question', function (): void {
    $legacyEleven = new TelegramScheduledMessageDelivery();
    $legacyEleven->control_type = 'oks_start_eleven';
    $legacyEleven->sequence_context = ['oks_assignments' => ['navigli' => 42]];
    expect(array_keys(OksControlSchedule::questionsFor($legacyEleven)))->toBe(['start', 'late'])
        ->and(OksControlSchedule::questionsFor($legacyEleven)['late']['skip'])->toBe('Поздних выездов нет');

    $legacyFinish = new TelegramScheduledMessageDelivery();
    $legacyFinish->control_type = 'oks_first_finish';
    $legacyFinish->sequence_context = [OksControlSchedule::SPLIT_FIRST_FINISH => true];
    expect(array_keys(OksControlSchedule::questionsFor($legacyFinish)))->toBe(['check', 'next']);

    $modernEleven = new TelegramScheduledMessageDelivery();
    $modernEleven->control_type = 'oks_start_eleven';
    $modernEleven->sequence_context = [OksControlSchedule::COPY_VERSION => 2];
    expect(array_keys(OksControlSchedule::questionsFor($modernEleven)))->toBe(['start']);

    $modernFinish = new TelegramScheduledMessageDelivery();
    $modernFinish->control_type = 'oks_first_finish';
    $modernFinish->sequence_context = [OksControlSchedule::COPY_VERSION => 2];
    expect(array_keys(OksControlSchedule::questionsFor($modernFinish)))->toBe(['check'])
        ->and(OksControlSchedule::questionsFor($modernFinish)['check']['skip'])->toBeNull();
});

it('uses the new question as the visible rich-card heading for one-question V2 controls', function (): void {
    foreach (['oks_schedule', 'oks_start_eleven', 'oks_linen', 'oks_first_progress',
        'oks_first_finish', 'oks_guest_arrivals', 'oks_couriers', 'oks_cleanings', 'oks_payments'] as $type) {
        $delivery = new TelegramScheduledMessageDelivery();
        $delivery->control_type = $type;
        $delivery->sequence_context = [OksControlSchedule::COPY_VERSION => 2];

        $question = array_values(OksControlSchedule::questionsFor($delivery))[0];
        expect(OksControlSchedule::headingFor($delivery))->toBe($question['title']);
    }
});

it('preserves historical and multi-question headings in rich Telegram cards', function (): void {
    $legacy = new TelegramScheduledMessageDelivery();
    $legacy->control_type = 'oks_start_eleven';
    $legacy->sequence_context = [];
    expect(OksControlSchedule::headingFor($legacy))->toBe('Уборки до 11:00 и поздние выезды');

    $multi = new TelegramScheduledMessageDelivery();
    $multi->control_type = 'oks_start_ten';
    $multi->sequence_context = [OksControlSchedule::COPY_VERSION => 2];
    expect(OksControlSchedule::questionsFor($multi))->toHaveCount(2)
        ->and(OksControlSchedule::headingFor($multi))->toBe('Начало уборок в 10:00');

    $oldSplit = new TelegramScheduledMessageDelivery();
    $oldSplit->control_type = 'oks_first_finish';
    $oldSplit->sequence_context = [OksControlSchedule::SPLIT_FIRST_FINISH => true];
    expect(OksControlSchedule::headingFor($oldSplit))->toBe('Повторный контроль первых уборок');
});
