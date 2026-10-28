<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonInterface;
use Gerenuk\Marketing\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Marketing\Automations\SyncAutomationEmails;
use Gerenuk\Marketing\Enums\AudienceType;
use Gerenuk\Marketing\Enums\AutomationTrigger;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\CustomFieldType;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Enums\SuppressionReason;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignLink;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\CampaignSnapshot;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Models\Tag;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Sending\HtmlToText;
use Gerenuk\Marketing\Sending\LinkExtractor;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fills a new workspace with realistic fake data for trying the app out locally:
 * contacts, lists, tags, segments, a provider, sent campaigns with engagement, and an automation.
 * Nothing is actually sent.
 */
#[Signature('app:demo-data {--contacts=500 : How many contacts to create} {--email=test@example.com : The user who owns the demo workspace}')]
#[Description('Create a workspace full of fake marketing data to test with')]
class DemoData extends Command
{
    private const PLANS = ['free', 'starter', 'pro', 'enterprise'];

    public function handle(CreateWorkspace $createWorkspace, CurrentWorkspace $currentWorkspace, SyncAutomationEmails $syncAutomationEmails): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('Demo data cannot be created in production.');

            return self::FAILURE;
        }

        $contactCount = max(1, (int) $this->option('contacts'));

        $user = User::query()->firstOrCreate(
            ['email' => $this->option('email')],
            ['name' => 'Test User', 'password' => 'password', 'email_verified_at' => now()],
        );

        $workspace = $createWorkspace->handle($user, 'Demo: '.fake()->company());
        $workspace->update(['postal_address' => fake()->address()]);

        $currentWorkspace->runAs($workspace, function () use ($workspace, $contactCount, $user, $syncAutomationEmails): void {
            DB::transaction(function () use ($workspace, $contactCount, $user, $syncAutomationEmails): void {
                $this->components->task('Custom fields, tags and lists', fn () => $this->createAudienceStructure());
                $this->components->task("{$contactCount} contacts", fn () => $this->createContacts($workspace, $contactCount));
                $this->components->task('Segments', fn () => $this->createSegments());
                $this->components->task('Provider and suppressions', fn () => $this->createProviderAndSuppressions());
                $this->components->task('Campaigns and engagement', fn () => $this->createCampaigns($workspace, $user));
                $this->components->task('Automation', fn () => $this->createAutomation($syncAutomationEmails));
            });
        });

        $user->switchWorkspace($workspace);

        $this->newLine();
        $this->components->info("Created workspace \"{$workspace->name}\" for {$user->email} (password is \"password\" if the user is new).");

        return self::SUCCESS;
    }

    private function createAudienceStructure(): void
    {
        foreach ([
            ['company', 'Company', CustomFieldType::Text],
            ['plan', 'Plan', CustomFieldType::Text],
            ['lifetime_value', 'Lifetime value', CustomFieldType::Number],
            ['birthday', 'Birthday', CustomFieldType::Date],
            ['vip', 'VIP', CustomFieldType::Boolean],
        ] as [$key, $label, $type]) {
            CustomFieldDefinition::create(['key' => $key, 'label' => $label, 'type' => $type]);
        }

        foreach (['customer', 'lead', 'newsletter', 'beta', 'webinar'] as $name) {
            Tag::create(['name' => $name]);
        }

        foreach ([
            'Newsletter' => 'Monthly news and tips.',
            'Product updates' => 'Release notes and new features.',
            'Customers' => 'Everyone with a paid plan.',
        ] as $name => $description) {
            ContactList::create(['name' => $name, 'description' => $description]);
        }
    }

    /**
     * Inserted in bulk so large counts stay quick, with sign-up dates spread over the last six months.
     */
    private function createContacts(Workspace $workspace, int $count): void
    {
        $faker = fake();
        $faker->unique(true);

        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            Contact::insert(array_map(function () use ($faker, $workspace): array {
                $createdAt = $faker->dateTimeBetween('-180 days');
                $unsubscribed = $faker->boolean(8);
                $plan = $faker->randomElement(self::PLANS);

                return [
                    'workspace_id' => $workspace->id,
                    'email' => Contact::normalizeEmail($faker->unique()->safeEmail()),
                    'first_name' => $faker->optional(0.9)->firstName(),
                    'last_name' => $faker->optional(0.8)->lastName(),
                    'custom_fields' => json_encode([
                        'company' => $faker->company(),
                        'plan' => $plan,
                        'lifetime_value' => $plan === 'free' ? 0 : $faker->numberBetween(50, 20000),
                        'birthday' => $faker->optional(0.6)->date(),
                        'vip' => $faker->boolean(10),
                    ]),
                    'status' => $unsubscribed ? ContactStatus::Unsubscribed->value : ContactStatus::Subscribed->value,
                    'source' => $faker->randomElement(ContactSource::cases())->value,
                    'unsubscribed_at' => $unsubscribed ? $faker->dateTimeBetween($createdAt) : null,
                    'archived_at' => $faker->boolean(3) ? now() : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }, $chunk));
        }

        $contacts = Contact::query()->get(['id', 'custom_fields']);
        $tags = Tag::query()->pluck('id', 'name');
        $lists = ContactList::query()->pluck('id', 'name');

        $tagRows = [];
        $listRows = [];

        foreach ($contacts as $contact) {
            $isCustomer = ($contact->custom_fields['plan'] ?? 'free') !== 'free';
            $contactTags = collect(['lead', 'newsletter', 'beta', 'webinar'])->filter(fn () => $faker->boolean(25));

            if ($isCustomer) {
                $contactTags->push('customer');
                $listRows[] = ['contact_list_id' => $lists['Customers'], 'contact_id' => $contact->id, 'created_at' => now()];
            }

            foreach ($contactTags as $tag) {
                $tagRows[] = ['tag_id' => $tags[$tag], 'contact_id' => $contact->id];
            }

            foreach (['Newsletter' => 70, 'Product updates' => 40] as $list => $chance) {
                if ($faker->boolean($chance)) {
                    $listRows[] = ['contact_list_id' => $lists[$list], 'contact_id' => $contact->id, 'created_at' => now()];
                }
            }
        }

        foreach (array_chunk($tagRows, 1000) as $rows) {
            DB::table('contact_tag')->insert($rows);
        }

        foreach (array_chunk($listRows, 1000) as $rows) {
            DB::table('contact_list_memberships')->insert($rows);
        }
    }

    private function createSegments(): void
    {
        $customerTag = Tag::query()->where('name', 'customer')->value('id');

        Segment::create(['name' => 'Recent customers', 'definition' => ['match' => 'all', 'conditions' => [
            ['type' => 'condition', 'field' => 'tag', 'operator' => 'has', 'value' => $customerTag],
            ['type' => 'condition', 'field' => 'created_at', 'operator' => 'within_last_days', 'value' => 60],
        ]]]);

        Segment::create(['name' => 'VIPs', 'definition' => ['match' => 'all', 'conditions' => [
            ['type' => 'condition', 'field' => 'custom:vip', 'operator' => 'is_true'],
        ]]]);

        Segment::create(['name' => 'High value', 'definition' => ['match' => 'any', 'conditions' => [
            ['type' => 'condition', 'field' => 'custom:lifetime_value', 'operator' => 'greater_than', 'value' => 10000],
            ['type' => 'condition', 'field' => 'custom:plan', 'operator' => 'equals', 'value' => 'enterprise'],
        ]]]);
    }

    /**
     * An SMTP provider pointed at a local mail catcher such as Mailpit, so any real send stays on this machine.
     */
    private function createProviderAndSuppressions(): void
    {
        Provider::create([
            'name' => 'Local SMTP',
            'type' => ProviderType::Smtp,
            'credentials' => ['host' => '127.0.0.1', 'port' => '1025', 'encryption' => 'starttls', 'username' => null, 'password' => null],
            'from_name' => 'Demo Team',
            'from_email' => 'hello@demo.test',
        ]);

        foreach (range(1, 8) as $index) {
            Suppression::suppress(
                fake()->unique()->safeEmail(),
                fake()->randomElement(SuppressionReason::cases()),
                fake()->optional()->sentence(),
            );
        }
    }

    private function createCampaigns(Workspace $workspace, User $user): void
    {
        $provider = Provider::query()->firstOrFail();
        $newsletter = ContactList::query()->where('name', 'Newsletter')->firstOrFail();

        $defaults = [
            'provider_id' => $provider->id,
            'created_by' => $user->id,
            'from_name' => 'Demo Team',
            'from_email' => 'hello@demo.test',
        ];

        Campaign::factory()->create([...$defaults, 'name' => 'Spring sale (draft)', 'html' => $this->emailHtml('Our spring sale is coming')]);

        Campaign::factory()->create([
            ...$defaults,
            'name' => 'Webinar invite',
            'subject' => 'Join our live product webinar',
            'html' => $this->emailHtml('Join us live next week'),
            'audience_type' => AudienceType::Lists,
            'list_ids' => [$newsletter->id],
            'status' => CampaignStatus::Scheduled,
            'scheduled_at' => now()->addDays(3)->setTime(10, 0),
        ]);

        foreach ([
            ['January newsletter', 'What we shipped in January', 120, false],
            ['Product update: dashboards', 'Say hello to the new dashboards', 60, false],
            ['February newsletter', 'Your February roundup', 21, true],
        ] as [$name, $subject, $daysAgo, $abTest]) {
            $sentAt = now()->subDays($daysAgo)->setTime(9, 30);

            $campaign = Campaign::factory()->create([
                ...$defaults,
                'name' => $name,
                'subject' => $subject,
                'html' => $this->emailHtml($subject),
                'audience_type' => AudienceType::Lists,
                'list_ids' => [$newsletter->id],
                'status' => CampaignStatus::Sent,
                'sending_started_at' => $sentAt,
                'sent_at' => $sentAt->copy()->addMinutes(12),
                'ab_enabled' => $abTest,
                'ab_subject_b' => $abTest ? $subject.' 🎉' : null,
                'ab_winner' => $abTest ? 'b' : null,
                'ab_decided_at' => $abTest ? $sentAt->copy()->addHours(4) : null,
            ]);

            $this->recordSend($workspace, $campaign, $newsletter, $sentAt);
        }
    }

    /**
     * Give a sent campaign recipients, links and an event stream with plausible delivery and engagement rates.
     */
    private function recordSend(Workspace $workspace, Campaign $campaign, ContactList $list, CarbonInterface $sentAt): void
    {
        $faker = fake();

        CampaignSnapshot::create([
            'campaign_id' => $campaign->id,
            'subject' => (string) $campaign->subject,
            'subject_b' => $campaign->ab_subject_b,
            'from_name' => (string) $campaign->from_name,
            'from_email' => (string) $campaign->from_email,
            'html' => (string) $campaign->html,
            'text' => HtmlToText::convert((string) $campaign->html),
        ]);

        $links = collect(LinkExtractor::urls((string) $campaign->html))->map(fn (string $url) => CampaignLink::create([
            'campaign_id' => $campaign->id,
            'url' => $url,
            'url_hash' => LinkExtractor::hash($url),
        ]));

        /** @var Collection<int, Contact> $contacts */
        $contacts = $list->contacts()
            ->where('status', ContactStatus::Subscribed)
            ->whereNull('archived_at')
            ->where('contacts.created_at', '<', $sentAt)
            ->get(['contacts.id', 'contacts.email']);

        $counts = array_fill_keys(['sent', 'delivered', 'bounced', 'complained', 'opened', 'clicked', 'unsubscribed'], 0);
        $events = [];
        $unsubscribedContactIds = [];
        $clicksPerLink = [];

        foreach ($contacts as $contact) {
            $at = $sentAt->copy()->addSeconds($faker->numberBetween(0, 600));
            $bounced = $faker->boolean(2);
            $complained = ! $bounced && $faker->boolean(0.3);
            $openedAt = ! $bounced && $faker->boolean(45) ? $at->copy()->addMinutes($faker->numberBetween(2, 4320)) : null;
            $clickedAt = $openedAt && $faker->boolean(30) ? $openedAt->copy()->addSeconds($faker->numberBetween(5, 300)) : null;
            $unsubscribedAt = $openedAt && ! $clickedAt && $faker->boolean(3) ? $openedAt->copy()->addMinute() : null;

            $recipient = CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'email' => $contact->email,
                'variant' => $campaign->ab_enabled ? $faker->randomElement(['a', 'b']) : null,
                'status' => match (true) {
                    $bounced => RecipientStatus::Bounced,
                    $complained => RecipientStatus::Complained,
                    default => RecipientStatus::Delivered,
                },
                'provider_message_id' => $faker->uuid(),
                'sent_at' => $at,
                'delivered_at' => $bounced ? null : $at->copy()->addSeconds(3),
                'opened_at' => $openedAt,
                'clicked_at' => $clickedAt,
                'bounced_at' => $bounced ? $at->copy()->addSeconds(3) : null,
                'complained_at' => $complained ? $at->copy()->addHours(2) : null,
                'unsubscribed_at' => $unsubscribedAt,
            ]);

            $event = fn (EventType $type, CarbonInterface $occurredAt, ?array $data = null): array => [
                'workspace_id' => $workspace->id,
                'contact_id' => $contact->id,
                'campaign_id' => $campaign->id,
                'campaign_recipient_id' => $recipient->id,
                'type' => $type->value,
                'data' => $data === null ? null : json_encode($data),
                'occurred_at' => $occurredAt,
            ];

            $events[] = $event(EventType::Sent, $at);
            $counts['sent']++;

            if ($bounced) {
                $events[] = $event(EventType::Bounced, $recipient->bounced_at, ['reason' => 'Mailbox does not exist']);
                $counts['bounced']++;

                continue;
            }

            $events[] = $event(EventType::Delivered, $recipient->delivered_at);
            $counts['delivered']++;

            if ($complained) {
                $events[] = $event(EventType::Complained, $recipient->complained_at);
                $counts['complained']++;
            }

            if ($openedAt) {
                $events[] = $event(EventType::Opened, $openedAt);
                $counts['opened']++;
            }

            if ($clickedAt && $links->isNotEmpty()) {
                $link = $links->random();
                $clicksPerLink[$link->id] = ($clicksPerLink[$link->id] ?? 0) + 1;
                $events[] = $event(EventType::Clicked, $clickedAt, ['url' => $link->url]);
                $counts['clicked']++;
            }

            if ($unsubscribedAt) {
                $events[] = $event(EventType::Unsubscribed, $unsubscribedAt);
                $unsubscribedContactIds[] = $contact->id;
                $counts['unsubscribed']++;
            }
        }

        foreach (array_chunk($events, 1000) as $rows) {
            ActivityEvent::insert($rows);
        }

        foreach ($clicksPerLink as $linkId => $clicks) {
            CampaignLink::query()->whereKey($linkId)->update(['clicks_count' => $clicks + intdiv($clicks, 4), 'unique_clicks_count' => $clicks]);
        }

        Contact::query()->whereKey($unsubscribedContactIds)->update(['status' => ContactStatus::Unsubscribed, 'unsubscribed_at' => $sentAt->copy()->addDay()]);

        $campaign->forceFill([
            'recipients_count' => $contacts->count(),
            ...collect($counts)->mapWithKeys(fn (int $count, string $key) => ["{$key}_count" => $count])->all(),
        ])->save();
    }

    /**
     * A draft, so the demo never enrols anyone or sends on its own.
     */
    private function createAutomation(SyncAutomationEmails $syncAutomationEmails): void
    {
        $provider = Provider::query()->firstOrFail();
        $newsletter = ContactList::query()->where('name', 'Newsletter')->firstOrFail();
        $email = fn (string $subject): array => [
            'provider_id' => $provider->id,
            'subject' => $subject,
            'from_name' => 'Demo Team',
            'from_email' => 'hello@demo.test',
            'html' => $this->emailHtml($subject),
        ];

        $automation = Automation::factory()->create([
            'name' => 'Welcome series',
            'trigger_type' => AutomationTrigger::ListJoined,
            'trigger_config' => ['list_id' => $newsletter->id],
            'definition' => ['steps' => [
                ['id' => 'welcome', 'type' => 'send_email', 'config' => $email('Welcome aboard, {{ first_name | friend }}')],
                ['id' => 'wait-3-days', 'type' => 'wait', 'config' => ['amount' => 3, 'unit' => 'days']],
                ['id' => 'getting-started', 'type' => 'send_email', 'config' => $email('Three tips to get started')],
            ]],
        ]);

        $syncAutomationEmails->handle($automation);
    }

    private function emailHtml(string $heading): string
    {
        $paragraphs = collect(fake()->paragraphs(2))->map(fn (string $paragraph) => "<p>{$paragraph}</p>")->implode('');

        return '<html><body><h1>'.e($heading).'</h1><p>Hi {{ first_name | there }},</p>'.$paragraphs
            .'<p><a href="https://demo.test/blog?utm_source=newsletter">Read the blog</a> or <a href="https://demo.test/pricing">see pricing</a>.</p>'
            .'<p><a href="{{ unsubscribe_url }}">Unsubscribe</a><br>{{ postal_address }}</p></body></html>';
    }
}
