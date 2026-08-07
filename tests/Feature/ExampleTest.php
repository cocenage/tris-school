<?php

it('returns a successful response', function () {
    $response = $this->get('/');

    $response->assertRedirectToRoute('landing.page');
});
