<?php

namespace Mattiasgeniar\PhpunitDbQuerycounter\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    public function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        /*
        include_once __DIR__.'/../database/migrations/create_phpunit_db_querycounter_table.php.stub';
        (new \CreatePackageTable())->up();
        */
    }
}
