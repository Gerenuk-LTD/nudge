<?php

namespace Gerenuk\Nudge\Sending;

/**
 * How a provider's delivery webhooks are authenticated.
 */
enum WebhookVerification: string
{
    /** The provider sends no delivery webhooks we can use. */
    case None = 'none';

    /** The provider signs requests with a key the user copies from the provider's dashboard. */
    case SigningSecret = 'signing_secret';

    /** The provider sends credentials we generate, as HTTP basic auth in the webhook URL. */
    case BasicAuth = 'basic_auth';

    /** Amazon SNS: each message is signed with an AWS certificate. */
    case Sns = 'sns';
}
