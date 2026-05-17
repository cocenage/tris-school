<x-filament-panels::page>
    <div class="space-y-6">

        <x-filament::section>
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                        Сообщения за день
                    </h2>

                    <p class="text-sm text-gray-500">
                        Выбери дату и посмотри, что бот собрал из рабочего Telegram-форума.
                    </p>
                </div>

                <div class="flex gap-3">
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="date"
                            wire:model.live="date"
                            wire:change="loadMessages"
                        />
                    </x-filament::input.wrapper>

                    <x-filament::button
                        icon="heroicon-o-arrow-path"
                        color="gray"
                        wire:click="loadMessages"
                    >
                        Обновить
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        @if($result)
            <x-filament::section>
                <x-slot name="heading">
                    Результат анализа
                </x-slot>

                <pre class="whitespace-pre-wrap rounded-2xl bg-gray-950 p-5 text-sm leading-6 text-white">{{ $result }}</pre>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">
                Найдено сообщений: {{ count($messages) }}
            </x-slot>

            <div class="space-y-3">
                @forelse($messages as $message)
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <div class="mb-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span class="font-semibold text-gray-900 dark:text-white">
                                {{ $message['author'] ?: 'Без имени' }}
                            </span>

                            <span>• {{ $message['time'] }}</span>

                            @if($message['thread_id'])
                                <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-gray-800">
                                    topic {{ $message['thread_id'] }}
                                </span>
                            @endif
                        </div>

                        <div class="whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-100">
                            {{ $message['text'] }}
                        </div>
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                        За выбранный день сообщений нет.
                    </div>
                @endforelse
            </div>
        </x-filament::section>
@if($prompt)

<x-filament::section>

    <x-slot name="heading">
        Промпт для ChatGPT
    </x-slot>

    <textarea
        rows="20"
        readonly
        class="w-full rounded-xl border-gray-300 text-sm"
    >{{ $prompt }}</textarea>

</x-filament::section>

@endif
    </div>
</x-filament-panels::page>