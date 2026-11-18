<?php

namespace Gerenuk\Marketing\Automations;

enum StepType: string
{
    case SendEmail = 'send_email';
    case Wait = 'wait';
    case Condition = 'condition';
    case UpdateContact = 'update_contact';
    case Webhook = 'webhook';
    case Exit = 'exit';
}
