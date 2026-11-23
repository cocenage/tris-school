<?php

it('rejects the Focus V2 staging preflight in a testing or production runtime', function (): void {
    config()->set('focus-v2.enabled', true);
    config()->set('app.url', 'https://focus-staging.example.test');

    $this->artisan('focus-v2:staging-check')->assertExitCode(1);

    $original = app()->environment();
    try {
        app()->detectEnvironment(fn (): string => 'production');
        $this->artisan('focus-v2:staging-check')->assertExitCode(1);
    } finally {
        app()->detectEnvironment(fn (): string => $original);
    }
});

it('accepts an explicitly isolated staging configuration without connecting to its database', function (): void {
    $original = app()->environment();
    config()->set('focus-v2.enabled', true);
    config()->set('app.url', 'https://focus-staging.example.test');
    config()->set('app.debug', false);
    config()->set('app.key', 'base64:dummy-non-production-test-only-key');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', '/tmp/focus-v2-dedicated-staging.sqlite');
    config()->set('database.connections.sqlite.url', null);
    config()->set('queue.default', 'sync');
    config()->set('mail.default', 'log');

    try {
        app()->detectEnvironment(fn (): string => 'staging');
        $this->artisan('focus-v2:staging-check')->assertExitCode(0);

        config()->set('app.url', 'https://academy.trisservice.eu');
        $this->artisan('focus-v2:staging-check')->assertExitCode(1);
    } finally {
        app()->detectEnvironment(fn (): string => $original);
    }
});

it('does not prune Focus V2 uploads when disabled or in production', function (): void {
    config()->set('focus-v2.enabled', false);
    $this->artisan('focus-v2:prune-media')->assertExitCode(0);

    $environment = app()->environment();
    try {
        config()->set('focus-v2.enabled', true);
        app()->detectEnvironment(fn (): string => 'production');
        $this->artisan('focus-v2:prune-media')->assertExitCode(0);
    } finally {
        app()->detectEnvironment(fn (): string => $environment);
    }
});
