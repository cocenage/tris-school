<?php

use App\Models\User;
use App\Services\TelegramAuthService;
use App\Services\TelegramUserNotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public string $firstName = '';

    public string $lastName = '';

    public string $birthday = '';

    public string $workStartedAt = '';

    public function mount(): void
    {
        $user = Auth::user();

        abort_unless($user, 403);

        if ($user->status !== 'registration_draft') {
            $this->redirect(app(TelegramAuthService::class)->redirectRouteFor($user), navigate: true);

            return;
        }

        $draft = $user->registration_draft ?? [];
        $this->firstName = (string) ($draft['first_name'] ?? '');
        $this->lastName = (string) ($draft['last_name'] ?? '');
        $this->birthday = (string) ($draft['birthday'] ?? '');
        $this->workStartedAt = (string) ($draft['work_started_at'] ?? '');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['firstName', 'lastName', 'birthday', 'workStartedAt'], true)) {
            $this->persistDraft();
        }
    }

    public function submit(): void
    {
        $user = Auth::user();

        abort_unless($user, 403);

        if ($user->status !== 'registration_draft') {
            $this->redirect(app(TelegramAuthService::class)->redirectRouteFor($user), navigate: true);

            return;
        }

        $this->firstName = trim($this->firstName);
        $this->lastName = trim($this->lastName);
        $this->persistDraft($user);

        $validated = $this->validate([
            'firstName' => ['required', 'string', 'max:120'],
            'lastName' => ['required', 'string', 'max:120'],
            'birthday' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'workStartedAt' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'firstName.required' => 'Укажите имя.',
            'lastName.required' => 'Укажите фамилию.',
            'birthday.required' => 'Укажите дату рождения.',
            'birthday.date_format' => 'Укажите корректную дату рождения.',
            'birthday.before_or_equal' => 'Дата рождения не может быть в будущем.',
            'workStartedAt.required' => 'Укажите дату начала работы.',
            'workStartedAt.date_format' => 'Укажите корректную дату начала работы.',
            'workStartedAt.before_or_equal' => 'Дата начала работы не может быть в будущем.',
        ]);

        $fullName = trim($validated['firstName'].' '.$validated['lastName']);

        $submittedUser = DB::transaction(function () use ($validated, $fullName): ?User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail(Auth::id());

            if ($lockedUser->status !== 'registration_draft') {
                return null;
            }

            $lockedUser->forceFill([
                'name' => $fullName,
                'birthday' => $validated['birthday'],
                'work_started_at' => $validated['workStartedAt'],
                'registration_draft' => null,
                'status' => 'pending',
            ])->save();

            return $lockedUser;
        });

        if (! $submittedUser) {
            $freshUser = Auth::user()->fresh();
            $this->redirect(app(TelegramAuthService::class)->redirectRouteFor($freshUser), navigate: true);

            return;
        }

        $notifications = app(TelegramUserNotificationService::class);
        $notifications->accessRequested($submittedUser);
        $notifications->accessPending($submittedUser);

        $this->redirectRoute('access.pending', navigate: true);
    }

    private function persistDraft(?User $user = null): void
    {
        $user ??= Auth::user();

        if (! $user || $user->status !== 'registration_draft') {
            return;
        }

        $user->forceFill([
            'registration_draft' => [
                'first_name' => mb_substr($this->firstName, 0, 120),
                'last_name' => mb_substr($this->lastName, 0, 120),
                'birthday' => mb_substr($this->birthday, 0, 10),
                'work_started_at' => mb_substr($this->workStartedAt, 0, 10),
            ],
        ])->saveQuietly();
    }
};
?>

<x-slot:header>
    <div class="flex h-[73px] items-center justify-center px-4 text-[18px] text-[#213259]">
        Регистрация
    </div>
</x-slot:header>

<div class="h-full min-h-0 overflow-y-auto bg-[#F4F7FB] px-4 pb-10 pt-5">
    <div class="mx-auto max-w-xl space-y-5 rounded-[30px] bg-white p-5 sm:p-7">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-[#111827]">Заявка на доступ</h1>
            <p class="mt-2 text-sm leading-6 text-black/60">
                Заполните анкету. Заявка уйдёт администратору только после нажатия кнопки внизу.
            </p>
        </div>

        <form wire:submit="submit" class="space-y-4">
            <label class="block text-sm font-medium text-[#213259]">
                Имя
                <input type="text" wire:model.live.debounce.500ms="firstName" maxlength="120" autocomplete="given-name" required class="mt-1 min-h-12 w-full rounded-2xl border border-black/15 px-4 text-base text-black outline-none focus:border-[#213259]">
                @error('firstName') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm font-medium text-[#213259]">
                Фамилия
                <input type="text" wire:model.live.debounce.500ms="lastName" maxlength="120" autocomplete="family-name" required class="mt-1 min-h-12 w-full rounded-2xl border border-black/15 px-4 text-base text-black outline-none focus:border-[#213259]">
                @error('lastName') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm font-medium text-[#213259]">
                Дата рождения
                <input type="date" wire:model.live.debounce.500ms="birthday" max="{{ today()->toDateString() }}" required class="mt-1 min-h-12 w-full rounded-2xl border border-black/15 px-4 text-base text-black outline-none focus:border-[#213259]">
                @error('birthday') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm font-medium text-[#213259]">
                Дата начала работы с нами
                <input type="date" wire:model.live.debounce.500ms="workStartedAt" max="{{ today()->toDateString() }}" required class="mt-1 min-h-12 w-full rounded-2xl border border-black/15 px-4 text-base text-black outline-none focus:border-[#213259]">
                <span class="mt-1 block text-xs font-normal text-black/50">Укажите дату начала работы в компании, а не дату регистрации на сайте.</span>
                @error('workStartedAt') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <button type="submit" wire:loading.attr="disabled" class="min-h-12 w-full rounded-full bg-[#213259] px-5 font-semibold text-white transition hover:bg-[#182745] disabled:opacity-60">
                Отправить заявку
            </button>
        </form>
    </div>
</div>
