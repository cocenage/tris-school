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

        @if(filled($exportText))
            <x-filament::section>
                <div class="space-y-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-bold">
                                Текст для анализа
                            </h2>

                            <p class="text-sm text-gray-500">
                                Скопируй этот текст и отправь в ChatGPT.
                            </p>
                        </div>

                        <button
                            type="button"
                            x-data="{ copied: false }"
                            x-on:click="
                                navigator.clipboard.writeText($refs.exportText.value);
                                copied = true;
                                setTimeout(() => copied = false, 1500);
                            "
                            class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                        >
                            <span x-show="!copied">Скопировать</span>
                            <span x-show="copied">Скопировано</span>
                        </button>
                    </div>

                    <textarea
                        x-ref="exportText"
                        readonly
                        class="min-h-[600px] w-full rounded-xl border border-gray-300 bg-white p-4 font-mono text-sm leading-6 text-gray-900"
                    >{{ $exportText }}</textarea>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>