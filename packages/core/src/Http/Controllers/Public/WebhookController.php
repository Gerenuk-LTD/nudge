<?php

namespace Gerenuk\Nudge\Http\Controllers\Public;

use Gerenuk\Nudge\Http\Controllers\Controller;
use Gerenuk\Nudge\Jobs\ProcessDeliveryEvents;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Sending\Webhooks\WebhookParser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use Throwable;

/**
 * Receives provider webhooks. Only verifies and queues; the events are applied by a job.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $token, WebhookParser $parser): Response
    {
        $provider = Provider::findByWebhookToken($token);

        abort_if($provider === null, 404);

        try {
            $events = $parser->parse($provider, $request);
        } catch (RejectWebhookException $exception) {
            return response($exception->getMessage(), $exception->getStatusCode());
        } catch (Throwable $exception) {
            // A payload the parser could not read. Report it, but answer like any other rejection.
            report($exception);

            return response('Malformed payload.', 400);
        }

        $provider->forceFill(['last_webhook_at' => now()])->saveQuietly();

        if ($events !== []) {
            ProcessDeliveryEvents::dispatch($provider->workspace_id, $events);
        }

        return response()->noContent();
    }
}
