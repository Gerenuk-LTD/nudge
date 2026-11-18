<?php

namespace Gerenuk\Nudge\Notifications;

use Gerenuk\Nudge\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly string $inviterName,
        public readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You have been invited to :workspace', ['workspace' => $this->workspace->name]))
            ->line(__(':inviter invited you to join the :workspace workspace.', [
                'inviter' => $this->inviterName,
                'workspace' => $this->workspace->name,
            ]))
            ->action(__('Accept invitation'), route('nudge.invitations.show', $this->token))
            ->line(__('This invitation expires in :days days.', ['days' => config('nudge.invitation_expiry_days')]));
    }
}
