<?php

it('redirects guests from the root page to login', function () {
    $this->get('/')
        ->assertRedirect(route('landing.page'));
});

it('shows the login page', function () {
    $this->get(route('landing.page'))
        ->assertOk();
});
