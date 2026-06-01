<?php

use Livewire\Component;

new class extends Component {
    public bool $open = false;

    public ?string $name = null;
    public ?string $birthday = null;
    public ?string $workStartedAt = null;

    public function mount(): void
    {
        $user = auth()->user();

        if (!$user) {
            return;
        }

        $this->name = $user->name;
        $this->birthday = $user->birthday?->format('Y-m-d');
        $this->workStartedAt = $user->work_started_at?->format('Y-m-d');

        $this->open = !$this->isProfileComplete();
    }

    protected function isProfileComplete(): bool
    {
        $user = auth()->user();

        return filled($user?->name)
            && filled($user?->birthday)
            && filled($user?->work_started_at);
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'birthday' => ['required', 'date'],
            'workStartedAt' => ['required', 'date'],
        ], [
            'name.required' => 'Введите имя.',
            'birthday.required' => 'Укажите дату рождения.',
            'workStartedAt.required' => 'Укажите дату начала работы.',
        ]);

        auth()->user()->forceFill([
            'name' => trim($this->name),
            'birthday' => $this->birthday,
            'work_started_at' => $this->workStartedAt,
        ])->save();

        $this->open = false;

        $this->dispatch(
            'toast',
            type: 'success',
            title: 'Профиль заполнен',
            message: 'Данные сохранены',
            duration: 3000,
        );
    }
};
?>

<div x-data="{
        open: @entangle('open').live,

        init() {
            this.$watch('open', value => {
                if (!value) {
                    this.$nextTick(() => {
                        if (@js($open)) {
                            this.open = true
                        }
                    })
                }
            })
        }
    }">
    <x-ui.bottom-sheet x-model="open" locked>
        <form wire:submit="save" class="p-[20px]">
            <div class="mb-[20px]">
                <h2 class="text-[24px] font-semibold leading-none tracking-[-0.03em]">
                    Заполните профиль
                </h2>

                <p class="mt-[8px] text-[14px] leading-[1.4] text-black/50">
                    Это нужно для заявок, календаря, выходных и рабочих уведомлений.
                </p>
            </div>

            <div class="space-y-[12px]">
                <div>
                    <label class="mb-[7px] block text-[14px] text-black/50">
                        Имя
                    </label>

                    <input type="text" wire:model="name"
                        class="h-[54px] w-full rounded-[22px] border border-[#E7E7E7] bg-[#F8F8F8] px-[16px] text-[16px] outline-none focus:border-[#213259]"
                        placeholder="Ваше имя">

                    @error('name')
                        <div class="mt-[6px] text-[13px] text-red-500">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div>
                    <label class="mb-[7px] block text-[14px] text-black/50">
                        Дата рождения
                    </label>

                    <input type="date" wire:model="birthday"
                        class="h-[54px] w-full rounded-[22px] border border-[#E7E7E7] bg-[#F8F8F8] px-[16px] text-[16px] outline-none focus:border-[#213259]">

                    @error('birthday')
                        <div class="mt-[6px] text-[13px] text-red-500">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div>
                    <label class="mb-[7px] block text-[14px] text-black/50">
                        Дата начала работы
                    </label>

                    <input type="date" wire:model="workStartedAt"
                        class="h-[54px] w-full rounded-[22px] border border-[#E7E7E7] bg-[#F8F8F8] px-[16px] text-[16px] outline-none focus:border-[#213259]">

                    @error('workStartedAt')
                        <div class="mt-[6px] text-[13px] text-red-500">
                            {{ $message }}
                        </div>
                    @enderror
                </div>
            </div>

            <div class="mt-[20px]">
                <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save"
                    class="w-full">
                    <span wire:loading.remove wire:target="save">
                        Сохранить
                    </span>

                    <span wire:loading wire:target="save">
                        Сохраняем...
                    </span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.bottom-sheet>
</div>