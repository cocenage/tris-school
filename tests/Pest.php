<?php

use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

$testEnvironment = static function (string $name): string {
    $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

    return is_string($value) ? $value : '';
};

TestDatabaseSafety::assertIsolated(
    $testEnvironment('APP_ENV'),
    $testEnvironment('DB_CONNECTION'),
    $testEnvironment('DB_DATABASE'),
    $testEnvironment('DB_URL'),
    $testEnvironment('DB_ANALYTICS_DATABASE'),
    $testEnvironment('DB_OLD_DATABASE'),
);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
