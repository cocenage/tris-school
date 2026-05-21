<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button wire:click="generate">
                    Сформировать выгрузку
                </x-filament::button>
            </div>
        </x-filament::section>

        @if($exportText)
            <x-filament::section>
                <div class="space-y-4">
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="text-lg font-bold">
                            Текст для анализа
                        </h2>

                        <button
                            type="button"
                            x-data
                            x-on:click="navigator.clipboard.writeText(@js($exportText))"
                            class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                        >
                            Скопировать
                        </button>
                    </div>

                    <textarea
                        readonly
                        class="min-h-[500px] w-full rounded-xl border border-gray-300 bg-white p-4 font-mono text-sm"
                    >{{ $exportText }}</textarea>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>