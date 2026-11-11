<?php

namespace Gerenuk\Marketing\Tests\Feature;

use Gerenuk\Marketing\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Routes are registered when the package boots, so turning the docs off needs the config set before that.
 */
class ApiDocsDisabledTest extends TestCase
{
    protected function disableApiDocs($app): void
    {
        $app['config']->set('marketing.api_docs.enabled', false);
    }

    #[DefineEnvironment('disableApiDocs')]
    public function test_the_docs_routes_are_not_registered_when_turned_off(): void
    {
        $prefix = config('marketing.api_prefix');

        $this->get("$prefix/docs")->assertNotFound();
        $this->getJson("$prefix/openapi.json")->assertNotFound();
    }
}
