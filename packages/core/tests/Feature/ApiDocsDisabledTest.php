<?php

namespace Gerenuk\Nudge\Tests\Feature;

use Gerenuk\Nudge\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Routes are registered when the package boots, so turning the docs off needs the config set before that.
 */
class ApiDocsDisabledTest extends TestCase
{
    protected function disableApiDocs($app): void
    {
        $app['config']->set('nudge.api_docs.enabled', false);
    }

    #[DefineEnvironment('disableApiDocs')]
    public function test_the_docs_routes_are_not_registered_when_turned_off(): void
    {
        $prefix = config('nudge.api_prefix');

        $this->get("$prefix/docs")->assertNotFound();
        $this->getJson("$prefix/openapi.json")->assertNotFound();
    }
}
