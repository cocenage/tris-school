<?php

use Livewire\Component;

new class extends Component {
    //
};
?>

<div class="">
    @if (isset($header))
        {{ $header }}
    @else
        <div class="rounded-[28px] bg-white p-4 shadow-sm">
            дефолтный хедер
        </div>
    @endif
</в>