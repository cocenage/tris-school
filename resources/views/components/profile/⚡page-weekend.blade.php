<?php

use App\Models\DayOffRequest;
use Carbon\Carbon;
use Livewire\Component;

new class extends Component
{
    public function getRequestsProperty()
    {
        return DayOffRequest::query()
            ->with(['days'])
            ->where('user_id', auth()->id())
            ->latest()
            ->get();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Одобрено',
            'rejected' => 'Отклонено',
            'partially_approved' => 'Частично одобрено',
            default => 'На рассмотрении',
        };
    }

    public function statusClasses(string $status): string
    {
        return match ($status) {
            'approved' => 'bg-[#E7F5EA] text-[#21663A]',
            'rejected' => 'bg-[#FDECEC] text-[#B42318]',
            'partially_approved' => 'bg-[#FFF4E5] text-[#B26A00]',
            default => 'bg-[#EEF4FF] text-[#2457A5]',
        };
    }

    public function dayStatusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Одобрено',
            'rejected' => 'Отказ',
            default => 'На рассмотрении',
        };
    }

    public function dayStatusClasses(string $status): string
    {
        return match ($status) {
            'approved' => 'bg-[#E7F5EA] text-[#21663A]',
            'rejected' => 'bg-[#FDECEC] text-[#B42318]',
            default => 'bg-[#EEF4FF] text-[#2457A5]',
        };
    }
};
?>

<div class="h-full flex flex-col overflow-hidden bg-[#F5F5F3]">
    <div class="px-[20px] pt-[20px] pb-[14px] flex items-center justify-between shrink-0">
        <div>
            <h1 class="text-[28px] font-semibold text-[#111111] leading-none">
                Мои заявки
            </h1>

            <p class="mt-[8px] text-[15px] text-[#7B7B76]">
                Все ваши заявки на выходные дни
            </p>
        </div>

        <a
            href=""
            class="w-[48px] h-[48px] rounded-full bg-[#111111] text-white flex items-center justify-center active:scale-[0.96] transition"
        >
            <x-heroicon-o-plus class="w-[24px] h-[24px] stroke-[2.5]" />
        </a>
    </div>

    <div class="flex-1 overflow-y-auto px-[20px] pb-[30px] space-y-[14px]">
        @forelse ($this->requests as $request)
            <div class="rounded-[30px] bg-white p-[18px] shadow-[0_8px_30px_rgba(0,0,0,0.04)]">
                <div class="flex items-start justify-between gap-[12px]">
                    <div>
                        <div class="text-[13px] text-[#8A8A84]">
                            {{ $request->created_at->translatedFormat('d F Y, H:i') }}
                        </div>

                        <div class="mt-[10px] text-[16px] font-semibold text-[#111111]">
                            {{ $this->statusLabel($request->status) }}
                        </div>
                    </div>

                    <div class="h-[34px] rounded-full px-[12px] flex items-center text-[13px] font-semibold whitespace-nowrap {{ $this->statusClasses($request->status) }}">
                        {{ $this->statusLabel($request->status) }}
                    </div>
                </div>

                <div class="mt-[16px] rounded-[22px] bg-[#F7F7F5] px-[14px] py-[12px] text-[15px] leading-[1.5] text-[#444440]">
                    {{ $request->reason }}
                </div>

                <div class="mt-[14px] space-y-[8px]">
                    @foreach ($request->days->sortBy('date') as $day)
                        <div class="rounded-[20px] bg-[#F8F8F6] px-[14px] py-[12px] flex items-start justify-between gap-[12px]">
                            <div>
                                <div class="text-[15px] font-semibold text-[#111111]">
                                    {{ Carbon::parse($day->date)->translatedFormat('d F Y') }}
                                </div>

                                @if ($day->admin_comment)
                                    <div class="mt-[4px] text-[13px] leading-[1.45] text-[#6F6F6A]">
                                        {{ $day->admin_comment }}
                                    </div>
                                @endif
                            </div>

                            <div class="h-[30px] rounded-full px-[10px] flex items-center text-[12px] font-semibold whitespace-nowrap {{ $this->dayStatusClasses($day->status) }}">
                                {{ $this->dayStatusLabel($day->status) }}
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($request->admin_comment)
                    <div class="mt-[12px] rounded-[22px] bg-[#FFF7E6] px-[14px] py-[12px]">
                        <div class="text-[13px] font-semibold text-[#B26A00]">
                            Общий комментарий администратора
                        </div>

                        <div class="mt-[6px] text-[14px] leading-[1.5] text-[#6B5A33]">
                            {{ $request->admin_comment }}
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="h-full flex flex-col items-center justify-center text-center px-[20px]">
                <div class="w-[72px] h-[72px] rounded-full bg-white flex items-center justify-center mb-[16px]">
                    <x-heroicon-o-calendar-days class="w-[34px] h-[34px] text-[#A5A5A0]" />
                </div>

                <h2 class="text-[20px] font-semibold text-[#111111]">
                    Пока нет заявок
                </h2>

                <p class="mt-[8px] text-[15px] text-[#7B7B76] leading-[1.5] max-w-[280px]">
                    Когда вы отправите первую заявку, она появится здесь.
                </p>
            </div>
        @endforelse
    </div>
</div>