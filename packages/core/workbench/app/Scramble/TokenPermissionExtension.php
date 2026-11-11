<?php

namespace Workbench\App\Scramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Documents which token permission each operation needs, and the errors every authenticated
 * route can return that the controllers do not raise themselves.
 */
class TokenPermissionExtension extends OperationExtension
{
    private const PREFIX = 'marketing.token-can:';

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $permission = $this->permission($routeInfo);

        if ($permission !== null) {
            $operation->setExtensionProperty('required-permission', $permission);
            $operation->description = trim("Requires a token with the `{$permission}` permission.\n\n".$operation->description);
        }

        $this->addMissing($operation, 401, 'Unauthenticated. The token is missing, invalid or expired.', 'Unauthenticated.');

        if ($permission !== null) {
            $this->addMissing($operation, 403, 'The token lacks the required permission.', "This token does not have the {$permission} permission.");
        }

        $this->addMissing(
            $operation,
            429,
            'Too many requests. Either the token exceeded its per-minute limit, or the caller\'s IP sent too many invalid tokens.',
            'Too Many Attempts.',
        );
    }

    private function permission(RouteInfo $routeInfo): ?string
    {
        foreach ($routeInfo->route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, self::PREFIX)) {
                return substr($middleware, strlen(self::PREFIX));
            }
        }

        return null;
    }

    private function addMissing(Operation $operation, int $code, string $description, string $message): void
    {
        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && (string) $response->code === (string) $code) {
                return;
            }
        }

        $body = (new ObjectType)
            ->addProperty('message', (new StringType)->example($message))
            ->setRequired(['message']);

        $operation->addResponse(
            Response::make($code)
                ->setDescription($description)
                ->setContent('application/json', Schema::fromType($body)),
        );
    }
}
