<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        // PHPUnit automatically loads <env> tags from phpunit.xml into $_ENV
        // but we need to ensure they're set before Laravel loads .env
        // Load phpunit.xml env vars manually if not already set
        if (!isset($_ENV['DB_DATABASE']) || $_ENV['DB_DATABASE'] !== 'mnjuz_testing') {
            $phpunitXmlPath = __DIR__.'/../phpunit.xml';
            if (file_exists($phpunitXmlPath)) {
                $xml = simplexml_load_file($phpunitXmlPath);
                if ($xml && isset($xml->php->env)) {
                    foreach ($xml->php->env as $env) {
                        $name = (string)$env['name'];
                        $value = (string)$env['value'];
                        $_ENV[$name] = $value;
                        $_SERVER[$name] = $value;
                    }
                }
            }
        }
        
        $app = require __DIR__.'/../bootstrap/app.php';

        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();

        return $app;
    }
}
