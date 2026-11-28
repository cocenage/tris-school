@php
    $backUrl = null;

    foreach ($scopes ?? [] as $scope) {
        if (! is_a($scope, \Filament\Resources\Pages\CreateRecord::class, true)
            && ! is_a($scope, \Filament\Resources\Pages\EditRecord::class, true)
            && ! is_a($scope, \Filament\Resources\Pages\ViewRecord::class, true)
            && ! is_a($scope, \Filament\Resources\Pages\ManageRelatedRecords::class, true)) {
            continue;
        }

        // This page already has its own explicit back action.
        if ($scope === \App\Filament\Resources\DayOffRequests\Pages\ViewDayOffRequest::class) {
            break;
        }

        $resource = $scope::getResource();

        if (isset($resource::getPages()['index']) && $resource::canViewAny()) {
            $backUrl = $resource::getUrl('index');
        }

        break;
    }
@endphp

@if ($backUrl)
    <a class="tris-resource-back" href="{{ $backUrl }}">
        <x-filament::icon icon="heroicon-o-arrow-left" class="h-5 w-5" />
        <span>Назад к списку</span>
    </a>
@endif
