@php
    $workspacePanel = \Filament\Facades\Filament::getCurrentPanel();
@endphp
@if(auth()->user()?->isApproved() && $workspacePanel && in_array($workspacePanel->getId(), ['admin', 'education'], true))
    <div data-tris-workspace="{{ hash_hmac('sha256', session()->getId().'|'.auth()->id().'|'.$workspacePanel->getId(), (string) config('app.key')) }}"
        data-tris-workspace-prefix="{{ '/'.trim($workspacePanel->getPath(), '/') }}" class="flex items-center">
        <button type="button" data-tris-favorite class="inline-flex h-11 w-11 items-center justify-center rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800"
            aria-label="Добавить в избранное" aria-pressed="false" title="Добавить в избранное">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                <path d="m12 3 2.8 5.7 6.3.9-4.6 4.4 1.1 6.3-5.6-3-5.6 3 1.1-6.3L3 9.6l6.2-.9L12 3Z"/>
            </svg>
        </button>
    </div>
    <script src="{{ asset('js/tris-workspace.js') }}" defer data-navigate-once></script>
@endif
