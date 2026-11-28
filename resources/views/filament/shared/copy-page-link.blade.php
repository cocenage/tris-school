@if(auth()->user()?->isApproved())
    <x-ui.copy-page-link :compact="true" />
@endif
