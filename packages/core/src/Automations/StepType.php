<?php

namespace Gerenuk\Nudge\Automations;

enum StepType: string
{
    case SendEmail = 'send_email';
    case Wait = 'wait';
    case Condition = 'condition';
    case UpdateContact = 'update_contact';
    case Webhook = 'webhook';
    case Exit = 'exit';
}
