<?php

declare(strict_types=1);

use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

abstract class KirbyTestCase extends TestCase
{
    private App $baseApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseApp = App::instance();
    }

    protected function tearDown(): void
    {
        App::instance($this->baseApp);
        parent::tearDown();
    }

    /**
     * Clone the current Kirby instance with the given plugin options set,
     * and make the clone the active App::instance() so that kirby()
     * inside the code under test picks it up.
     *
     * @param  array<string, mixed>  $options
     */
    protected function kirbyWithOptions(array $options): App
    {
        $app = App::instance()->clone([
            'options' => $options,
        ]);

        App::instance($app);

        return $app;
    }
}
