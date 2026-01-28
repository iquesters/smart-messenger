<?php

namespace Iquesters\SmartMessenger\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Iquesters\SmartMessenger\SmartMessengerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            SmartMessengerServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Load package migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}