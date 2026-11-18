<?php

namespace Gerenuk\Marketing\Sending;

/**
 * Names of the metadata we attach to every message so webhook events can be traced back to a recipient.
 */
final class MessageHeaders
{
    public const RECIPIENT_KEY = 'gmk_recipient';

    public const WORKSPACE_KEY = 'gmk_workspace';
}
