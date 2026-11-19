<?php

namespace Gerenuk\Nudge\Sending;

use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\CampaignLink;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Gerenuk\Nudge\Models\CampaignSnapshot;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\Workspace;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Builds one recipient's copy of a campaign: personalised, with tracked links, an open pixel,
 * an unsubscribe link and footer, and the headers that make one-click unsubscribe work.
 */
class CampaignMessage
{
    public function __construct(private readonly TrackingUrls $urls) {}

    /**
     * @param  array<string, CampaignLink>  $linksByHash
     */
    public function build(
        Campaign $campaign,
        CampaignSnapshot $snapshot,
        CampaignRecipient $recipient,
        Contact $contact,
        Workspace $workspace,
        array $linksByHash,
        ?string $configurationSet = null,
    ): Email {
        $unsubscribeUrl = $this->urls->unsubscribe($recipient);
        $extra = [
            'unsubscribe_url' => $unsubscribeUrl,
            'postal_address' => e((string) $workspace->postal_address),
        ];

        $content = $snapshot->variant($recipient->variant);

        $html = MergeTags::render($content['html'], $contact, $extra, escapeHtml: true);

        if ($campaign->track_clicks) {
            $html = LinkExtractor::rewrite($html, function (string $url) use ($linksByHash, $recipient): ?string {
                $link = $linksByHash[LinkExtractor::hash($url)] ?? null;

                return $link === null ? null : $this->urls->click($recipient, $link);
            });
        }

        $html = $this->withFooter($html, $content['html'], $unsubscribeUrl, (string) $workspace->postal_address);
        $preheader = MergeTags::render((string) $snapshot->preheader, $contact, ['unsubscribe_url' => $unsubscribeUrl, 'postal_address' => (string) $workspace->postal_address], escapeHtml: false);
        $html = $this->withPreheader($html, $preheader);

        if ($campaign->track_opens) {
            $html = $this->withOpenPixel($html, $this->urls->open($recipient));
        }

        $text = MergeTags::render($content['text'], $contact, ['unsubscribe_url' => $unsubscribeUrl, 'postal_address' => (string) $workspace->postal_address], escapeHtml: false);

        if (! str_contains($content['text'], 'unsubscribe_url')) {
            $text .= "\n\n--\n".trim((string) $workspace->postal_address)."\nUnsubscribe: {$unsubscribeUrl}";
        }

        $email = (new Email)
            ->from(new Address($snapshot->from_email, $snapshot->from_name))
            ->to(new Address($recipient->email, trim("{$contact->first_name} {$contact->last_name}")))
            ->subject(MergeTags::render($content['subject'], $contact, [], escapeHtml: false))
            ->html($html)
            ->text($text);

        if ($snapshot->reply_to) {
            $email->replyTo($snapshot->reply_to);
        }

        $headers = $email->getHeaders();
        $headers->addTextHeader('List-Unsubscribe', "<{$unsubscribeUrl}>");
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $headers->add(new MetadataHeader(MessageHeaders::RECIPIENT_KEY, (string) $recipient->id));
        $headers->add(new MetadataHeader(MessageHeaders::WORKSPACE_KEY, (string) $workspace->id));

        if ($configurationSet) {
            $headers->addTextHeader('X-SES-CONFIGURATION-SET', $configurationSet);
        }

        return $email;
    }

    /**
     * Every campaign must carry an unsubscribe link and the sender's postal address. When the template
     * does not place them itself, they are added at the bottom.
     */
    private function withFooter(string $html, string $template, string $unsubscribeUrl, string $postalAddress): string
    {
        $needsUnsubscribe = ! in_array('unsubscribe_url', MergeTags::tagsIn($template), true);
        $needsAddress = ! in_array('postal_address', MergeTags::tagsIn($template), true);

        if (! $needsUnsubscribe && ! $needsAddress) {
            return $html;
        }

        $lines = [];

        if ($needsAddress && trim($postalAddress) !== '') {
            $lines[] = nl2br(e(trim($postalAddress)));
        }

        if ($needsUnsubscribe) {
            $lines[] = '<a href="'.e($unsubscribeUrl).'" style="color:#6b7280;">Unsubscribe</a>';
        }

        $footer = '<div style="margin-top:32px;padding:16px;font-family:sans-serif;font-size:12px;line-height:1.5;color:#6b7280;text-align:center;">'
            .implode('<br>', $lines).'</div>';

        return self::insertBeforeBodyEnd($html, $footer);
    }

    private function withPreheader(string $html, string $preheader): string
    {
        if (trim($preheader) === '') {
            return $html;
        }

        $hidden = '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'.e($preheader).'</div>';

        return preg_match('/<body\b[^>]*>/i', $html)
            ? (string) preg_replace('/(<body\b[^>]*>)/i', '$1'.$hidden, $html, 1)
            : $hidden.$html;
    }

    private function withOpenPixel(string $html, string $pixelUrl): string
    {
        return self::insertBeforeBodyEnd($html, '<img src="'.e($pixelUrl).'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">');
    }

    private static function insertBeforeBodyEnd(string $html, string $fragment): string
    {
        $position = strripos($html, '</body>');

        return $position === false ? $html.$fragment : substr_replace($html, $fragment, $position, 0);
    }
}
