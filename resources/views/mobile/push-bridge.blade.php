@if(\App\Services\MobilePush\PushService::enabled() && auth()->user()?->isApproved() && !app(\App\Services\RolePreviewContext::class)->isActive())
    <meta name="tris-mobile-push" content="{{ session('mobile_push_opted_in') ? 'enabled' : 'available' }}">
    <script src="{{ asset('js/tris-mobile-push.js') }}" defer data-navigate-once></script>
@endif
