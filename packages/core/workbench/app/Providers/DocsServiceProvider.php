<?php

namespace Workbench\App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Combined\AllOf;
use Dedoc\Scramble\Support\Generator\MediaType;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Gerenuk\Nudge\Support\ContactRules;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Scramble\TokenPermissionExtension;

/**
 * Configures Scramble for exporting the API spec. It only exists under Testbench, so nothing
 * Scramble-related is loaded in an install of the package.
 *
 * Changing an API endpoint? Re-export the spec: `cd packages/core && vendor/bin/testbench scramble:export --path=docs/openapi.json`.
 * CI fails when docs/openapi.json is out of date.
 */
class DocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'scramble.api_path' => config('nudge.api_prefix'),
            'scramble.info.version' => '1',
            'scramble.ui.title' => 'Nudge API',
            // Joined with explicit newlines so the export is identical on every platform.
            'scramble.info.description' => implode("\n\n", [
                'Manage contacts, lists, custom fields and events in a workspace.',
                'Every request needs a workspace API token, sent as `Authorization: Bearer <token>`. A token carries permissions; each operation says which one it needs. Requests are limited per token each minute.',
                'Request bodies are flat JSON. A record comes back inside `data`. Lists are cursor-paginated: pass `per_page` and `cursor`, and follow `meta.next_cursor` until it is null. Errors have a stable `code` and a `message`, and 422 adds `errors` keyed by field. A 429 response includes a `Retry-After` header.',
            ]),
            // The served copy replaces this with each install's own base URL.
            'scramble.servers' => ['Default' => 'api/v1'],
        ]);

        // Keeps the exported base URL the same on every machine.
        URL::forceRootUrl('https://your-domain.example');
    }

    public function boot(): void
    {
        Scramble::configure()
            ->routes(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'nudge.api.')
                && ! str_starts_with((string) $route->getName(), 'nudge.api.docs'))
            ->withOperationTransformers(TokenPermissionExtension::class)
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                        ->setDescription('A workspace API token (starts `gmk_`), created under Workspace settings → API tokens.')
                );

                $this->tidy($openApi);
            });
    }

    /**
     * Two clean-ups Scramble cannot make from the code alone:
     * the contact upsert validates against `ContactRules::rules()`, built at runtime, so Scramble adds an empty
     * `ContactRules` schema next to the documented body; and empty 204 responses should not describe a body.
     */
    private function tidy(OpenApi $openApi): void
    {
        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ($operation->requestBodyObject?->content ?? [] as $mediaType => $content) {
                    $schema = $content instanceof MediaType ? $content->schema : $content;
                    $type = $schema instanceof Schema ? $schema->type : null;

                    if (! $type instanceof AllOf) {
                        continue;
                    }

                    $items = array_values(array_filter(
                        $type->items,
                        fn ($item): bool => ! ($item instanceof Reference && $item->fullName === ContactRules::class),
                    ));

                    if (count($items) !== 1) {
                        continue;
                    }

                    $cleaned = Schema::fromType($items[0]);

                    if ($content instanceof MediaType) {
                        $content->setSchema($cleaned);
                    } else {
                        $operation->requestBodyObject->setContent($mediaType, $cleaned);
                    }
                }

                $this->moveBodyParameters($operation);

                foreach ($operation->responses ?? [] as $response) {
                    if ($response instanceof Response && (string) $response->code === '204') {
                        $response->content = [];
                    }
                }
            }
        }

        $openApi->components->removeSchema(ContactRules::class);

        $this->describeValidationError($openApi);

        // Scramble also registers empty schemas for models it could not read columns from; drop any nothing refers to.
        $document = json_encode($openApi->toArray(), JSON_UNESCAPED_SLASHES);

        foreach (array_keys($openApi->components->schemas) as $name) {
            if (! str_contains((string) $document, '#/components/schemas/'.$name.'"')) {
                $openApi->components->removeSchema($name);
            }
        }
    }

    /**
     * Scramble documents every validation failure with its own `message` and `errors` body; the API also sends a `code`.
     */
    private function describeValidationError(OpenApi $openApi): void
    {
        $errors = (new ObjectType)
            ->additionalProperties((new ArrayType)->setItems(new StringType))
            ->setDescription('A detailed description of each field that failed validation.');

        $body = (new ObjectType)
            ->addProperty('code', (new StringType)->example('validation_failed'))
            ->addProperty('message', (new StringType)->setDescription('Errors overview.'))
            ->addProperty('errors', $errors)
            ->setRequired(['code', 'message', 'errors']);

        foreach ($openApi->components->responses as $response) {
            if ($response instanceof Response && $response->description === 'Validation error') {
                $response->setContent('application/json', Schema::fromType($body));
            }
        }
    }

    /**
     * OpenAPI has no `in: body` parameter. Scramble documents the inputs of a DELETE route that way, but the API
     * reads them from the JSON body, so they are moved into a request body.
     */
    private function moveBodyParameters(Operation $operation): void
    {
        $body = array_values(array_filter(
            $operation->parameters,
            fn ($parameter): bool => $parameter instanceof Parameter && $parameter->in === 'body',
        ));

        if ($body === []) {
            return;
        }

        $object = new ObjectType;
        $required = [];

        foreach ($body as $parameter) {
            $name = str_replace('[]', '', $parameter->name);
            $type = $parameter->schema instanceof Schema ? clone $parameter->schema->type : new StringType;

            if ($parameter->description !== '') {
                $type->setDescription($parameter->description);
            }

            $object->addProperty($name, $type);

            if ($parameter->required) {
                $required[] = $name;
            }
        }

        $object->setRequired($required);

        $operation->parameters = array_values(array_filter($operation->parameters, fn ($parameter): bool => ! in_array($parameter, $body, true)));
        $operation->addRequestBodyObject(RequestBodyObject::make()->setContent('application/json', Schema::fromType($object))->required());
    }
}
