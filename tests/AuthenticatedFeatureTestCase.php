<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesDemoUsers;

abstract class AuthenticatedFeatureTestCase extends TestCase
{
    use AuthenticatesDemoUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsDemoUser('administrador');
    }
}
