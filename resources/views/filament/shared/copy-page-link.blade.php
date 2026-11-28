@if(auth()->user()?->isApproved())
    @include('filament.shared.workspace-bookmarks')
    <x-ui.copy-page-link :compact="true" />
@endif
