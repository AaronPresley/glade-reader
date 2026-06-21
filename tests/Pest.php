<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest Test Case
|--------------------------------------------------------------------------
|
| Feature and domain integration tests run through Laravel's base test case.
|
*/

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(TestCase::class)->in(__DIR__.'/../test/Domain');
