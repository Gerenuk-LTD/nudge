<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonInterface;
use Gerenuk\Nudge\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Nudge\Automations\SyncAutomationEmails;
use Gerenuk\Nudge\Enums\AudienceType;
use Gerenuk\Nudge\Enums\AutomationRunStatus;
use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Enums\ContactSource;
use Gerenuk\Nudge\Enums\ContactStatus;
use Gerenuk\Nudge\Enums\CustomFieldType;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Enums\ImportStatus;
use Gerenuk\Nudge\Enums\Permission;
use Gerenuk\Nudge\Enums\RecipientStatus;
use Gerenuk\Nudge\Enums\SuppressionReason;
use Gerenuk\Nudge\Models\ActivityEvent;
use Gerenuk\Nudge\Models\ApiRequest;
use Gerenuk\Nudge\Models\ApiToken;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\AutomationRun;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\CampaignLink;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Gerenuk\Nudge\Models\CampaignSnapshot;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\ContactList;
use Gerenuk\Nudge\Models\CustomFieldDefinition;
use Gerenuk\Nudge\Models\EmailTemplate;
use Gerenuk\Nudge\Models\Import;
use Gerenuk\Nudge\Models\ImportError;
use Gerenuk\Nudge\Models\Invitation;
use Gerenuk\Nudge\Models\Membership;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Models\Role;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Models\Suppression;
use Gerenuk\Nudge\Models\Tag;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Sending\HtmlToText;
use Gerenuk\Nudge\Sending\LinkExtractor;
use Gerenuk\Nudge\Sending\ProviderType;
use Gerenuk\Nudge\Templates\EmailEditor;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fills a new workspace with realistic fake data for trying the app out locally:
 * contacts, lists, tags, segments, a provider, sent campaigns with engagement, teammates, templates, imports,
 * automations with runs, API tokens and log, and custom events.
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
            ['name' => 'Test User', 'password' => 'password'],
        );

        if ($user->wasRecentlyCreated) {
            $user->markEmailAsVerified();
        }

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
                $this->components->task('Team, roles and invitation', fn () => $this->createTeam($workspace, $user));
                $this->components->task('Email templates', fn () => $this->createTemplates($user));
                $this->components->task('Imports', fn () => $this->createImports($user));
                $this->components->task('Automation runs and more automations', fn () => $this->createAutomationRuns($syncAutomationEmails));
                $this->components->task('API tokens and log', fn () => $this->createApiLog($workspace, $user));
                $this->components->task('Custom events', fn () => $this->createCustomEvents($workspace));
            });
        });

        $user->switchWorkspace($workspace);

        $this->newLine();
        $this->components->info("Created workspace \"{$workspace->name}\" for {$user->email} (password is \"password\" if the user is new).");
        $this->components->info('Teammates: admin@, editor@ and viewer@example.com (password "password").');

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
                ...($abTest ? [
                    'ab_subject_b' => $subject.' 🎉',
                    'ab_winner' => 'b',
                    'ab_decided_at' => $sentAt->copy()->addHours(4),
                ] : []),
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

    private function createTeam(Workspace $workspace, User $owner): void
    {
        foreach (['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer'] as $local => $roleName) {
            $teammate = User::query()->firstOrCreate(
                ['email' => "{$local}@example.com"],
                ['name' => ucfirst($local).' User', 'password' => 'password'],
            );

            if ($teammate->wasRecentlyCreated) {
                $teammate->markEmailAsVerified();
            }

            Membership::query()->firstOrCreate([
                'workspace_id' => $workspace->id,
                'user_id' => $teammate->id,
            ], ['role_id' => Role::query()->where('name', $roleName)->value('id')]);

            $teammate->forgetResolvedMemberships();
        }

        Role::create([
            'name' => 'Importer',
            'permissions' => [Permission::ContactsView->value, Permission::ContactsImport->value, Permission::CampaignsView->value],
            'is_owner' => false,
        ]);

        // Only a hash of the token is stored, and nobody knows the token, so the invitation can't be accepted.
        Invitation::create([
            'email' => 'newhire@example.com',
            'role_id' => Role::query()->where('name', 'Editor')->value('id'),
            'invited_by' => $owner->id,
            'token_hash' => hash('sha256', Str::random(48)),
            'expires_at' => now()->addDays(config('nudge.invitation_expiry_days')),
        ]);
    }

    private function createTemplates(User $owner): void
    {
        foreach ([
            ['Newsletter', 'A monthly roundup with a headline, a story and a button.', 'Your monthly roundup, {{ first_name | friend }}'],
            ['Product announcement', 'Announce a new feature with one clear call to action.', 'Introducing something new'],
        ] as [$name, $description, $subject]) {
            EmailTemplate::create([
                'created_by' => $owner->id,
                'name' => $name,
                'description' => $description,
                'subject' => $subject,
                'preheader' => 'A quick read for you.',
                'editor' => EmailEditor::BLOCKS,
                'editor_state' => $this->templateDocument($name),
                'html' => '',
            ]);
        }

        EmailTemplate::create([
            'created_by' => $owner->id,
            'name' => 'Plain announcement',
            'description' => 'Hand-written HTML with no design.',
            'subject' => 'A quick announcement',
            'html' => $this->emailHtml('A quick announcement'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateDocument(string $heading): array
    {
        $text = fn (string $id, string $html): array => ['id' => $id, 'type' => 'text', 'html' => $html, 'align' => 'left'];

        return [
            'version' => 1,
            'settings' => ['backdrop' => '#f4f4f5', 'canvas' => '#ffffff', 'width' => 600, 'font' => 'Arial, Helvetica, sans-serif', 'text' => '#18181b', 'link' => '#2563eb'],
            'blocks' => [
                ['id' => 'title', 'type' => 'heading', 'html' => $heading, 'level' => 1, 'align' => 'left'],
                $text('greeting', '<p>Hi {{ first_name | there }},</p><p>'.fake()->paragraph().'</p>'),
                ['id' => 'cta', 'type' => 'button', 'label' => 'Read more', 'url' => 'https://demo.test/blog', 'align' => 'left', 'background' => '#18181b', 'color' => '#ffffff'],
                ['id' => 'rule', 'type' => 'divider'],
                ['id' => 'footer', 'type' => 'footer', 'html' => '<p>You are receiving this because you signed up at demo.test.</p>', 'align' => 'left'],
            ],
        ];
    }

    private function createImports(User $owner): void
    {
        $disk = Storage::disk(config('nudge.imports.disk'));
        $customers = ContactList::query()->where('name', 'Customers')->firstOrFail();
        $workspaceId = $customers->workspace_id;
        $headers = ['email', 'first_name', 'last_name'];

        $csv = fn (int $rows): string => implode("\n", [implode(',', $headers), ...array_map(
            fn (int $i): string => "customer{$i}@example.org,First{$i},Last{$i}",
            range(1, $rows),
        )])."\n";

        $disk->put($path = "imports/{$workspaceId}/demo-customers-march.csv", $csv(120));

        $import = Import::create([
            'user_id' => $owner->id,
            'contact_list_id' => $customers->id,
            'file_path' => $path,
            'original_filename' => 'customers-march.csv',
            'headers' => $headers,
            'column_mapping' => ['email', 'first_name', 'last_name'],
            'tags' => ['customer'],
            'status' => ImportStatus::Completed,
            'total_rows' => 120,
            'processed_rows' => 120,
            'created_count' => 80,
            'updated_count' => 30,
            'failed_count' => 10,
            'started_at' => now()->subDays(12)->setTime(14, 0),
            'finished_at' => now()->subDays(12)->setTime(14, 1),
        ]);
        $import->forceFill(['created_at' => now()->subDays(12)->setTime(13, 58)])->save();

        $messages = ['The email field must be a valid email address.', 'Duplicate row', 'The email field is required.'];

        foreach (range(1, 10) as $i) {
            ImportError::create([
                'import_id' => $import->id,
                'row_number' => $i * 11,
                'email' => $i % 3 === 0 ? null : "broken{$i}@example",
                'message' => $messages[$i % 3],
                'created_at' => $import->finished_at,
            ]);
        }

        $disk->put($path = "imports/{$workspaceId}/demo-leads.csv", $csv(25));

        $failed = Import::create([
            'user_id' => $owner->id,
            'contact_list_id' => null,
            'file_path' => $path,
            'original_filename' => 'leads.csv',
            'headers' => $headers,
            'column_mapping' => ['email', 'first_name', 'last_name'],
            'status' => ImportStatus::Failed,
            'total_rows' => 25,
            'processed_rows' => 0,
            'started_at' => now()->subDays(3)->setTime(10, 0),
            'finished_at' => now()->subDays(3)->setTime(10, 0)->addSeconds(4),
        ]);
        $failed->forceFill(['created_at' => now()->subDays(3)->setTime(9, 58)])->save();
    }

    /**
     * Give "Welcome series" a live history, and add a paused and a draft automation beside it.
     */
    private function createAutomationRuns(SyncAutomationEmails $syncAutomationEmails): void
    {
        $welcome = Automation::query()->where('name', 'Welcome series')->firstOrFail();
        $newsletter = ContactList::query()->where('name', 'Newsletter')->firstOrFail();

        $contactIds = $newsletter->contacts()->whereNull('archived_at')->inRandomOrder()->limit(60)->pluck('contacts.id');
        $total = $contactIds->count();
        $completed = (int) round($total * 25 / 60);
        $waiting = (int) round($total * 20 / 60);
        $active = (int) round($total * 10 / 60);
        $faker = fake();
        $rows = [];

        foreach ($contactIds->values() as $index => $contactId) {
            $status = match (true) {
                $index < $completed => AutomationRunStatus::Completed,
                $index < $completed + $waiting => AutomationRunStatus::Waiting,
                $index < $completed + $waiting + $active => AutomationRunStatus::Active,
                default => AutomationRunStatus::Exited,
            };
            $startedAt = now()->subDays($faker->numberBetween(1, 38))->subMinutes($faker->numberBetween(0, 1400));

            $rows[] = [
                'workspace_id' => $welcome->workspace_id,
                'automation_id' => $welcome->id,
                'contact_id' => $contactId,
                'status' => $status->value,
                'current_step_id' => match ($status) {
                    AutomationRunStatus::Waiting => 'wait-3-days',
                    AutomationRunStatus::Active => 'welcome',
                    default => null,
                },
                'next_run_at' => match ($status) {
                    AutomationRunStatus::Waiting => now()->addHours($faker->numberBetween(1, 72)),
                    AutomationRunStatus::Active => now()->addMinutes(5),
                    default => null,
                },
                'context' => json_encode([]),
                'exit_reason' => $status === AutomationRunStatus::Exited ? 'unsubscribed' : null,
                'completed_at' => $status === AutomationRunStatus::Completed ? $startedAt->copy()->addDays(3) : null,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ];
        }

        AutomationRun::insert($rows);

        $welcome->forceFill([
            'status' => AutomationStatus::Active,
            'activated_at' => now()->subDays(40),
            'entered_count' => $total,
            'completed_count' => $completed,
        ])->save();

        $provider = Provider::query()->firstOrFail();
        $email = fn (string $subject): array => [
            'provider_id' => $provider->id,
            'subject' => $subject,
            'from_name' => 'Demo Team',
            'from_email' => 'hello@demo.test',
            'html' => $this->emailHtml($subject),
        ];

        $winBack = Automation::factory()->create([
            'name' => 'Win-back',
            'status' => AutomationStatus::Paused,
            'trigger_type' => AutomationTrigger::ListJoined,
            'trigger_config' => ['list_id' => $newsletter->id],
            'activated_at' => now()->subDays(20),
            'definition' => ['steps' => [
                ['id' => 'miss-you', 'type' => 'send_email', 'config' => $email('We miss you, {{ first_name | friend }}')],
                ['id' => 'wait-a-week', 'type' => 'wait', 'config' => ['amount' => 7, 'unit' => 'days']],
                ['id' => 'last-chance', 'type' => 'send_email', 'config' => $email('A little something to come back for')],
            ]],
        ]);

        $birthday = Automation::factory()->create([
            'name' => 'Birthday offer',
            'status' => AutomationStatus::Draft,
            'trigger_type' => AutomationTrigger::DateField,
            'trigger_config' => ['field_key' => 'birthday', 'offset_days' => 0, 'repeat' => 'yearly'],
            'definition' => ['steps' => [
                ['id' => 'happy-birthday', 'type' => 'send_email', 'config' => $email('Happy birthday, {{ first_name | friend }}')],
            ]],
        ]);

        $syncAutomationEmails->handle($welcome);
        $syncAutomationEmails->handle($winBack);
        $syncAutomationEmails->handle($birthday);
    }

    private function createApiLog(Workspace $workspace, User $owner): void
    {
        $faker = fake();
        $emails = Contact::query()->limit(50)->pluck('email')->all();

        $tokens = [
            ApiToken::create([
                'created_by' => $owner->id,
                'name' => 'Zapier sync',
                'token_hash' => hash('sha256', Str::random(48)),
                'permissions' => [Permission::ContactsView->value, Permission::ContactsManage->value],
                'last_used_at' => now()->subMinutes($faker->numberBetween(1, 59)),
            ]),
            ApiToken::create([
                'created_by' => $owner->id,
                'name' => 'Website signup form',
                'token_hash' => hash('sha256', Str::random(48)),
                'permissions' => [Permission::ContactsManage->value],
                'last_used_at' => now()->subMinutes($faker->numberBetween(1, 59)),
            ]),
        ];

        $errors = [
            [422, 'PUT', 'contacts/{email}', 'contacts/not-an-email', ['code' => 'validation_failed', 'message' => 'The email field must be a valid email address.', 'errors' => ['email' => ['The email field must be a valid email address.']]]],
            [422, 'POST', 'events', 'events', ['code' => 'validation_failed', 'message' => 'The event field is required.', 'errors' => ['event' => ['The event field is required.']]]],
            [404, 'POST', 'events', 'events', ['code' => 'contact_not_found', 'message' => 'No contact with that email. Send create_contact: true to create one.']],
            [404, 'GET', 'contacts/{email}', 'contacts/nobody@example.org', ['code' => 'not_found', 'message' => 'Not found.']],
            [403, 'GET', 'segments', 'segments', ['code' => 'missing_permission', 'message' => 'This token does not have the contacts.view permission.']],
            [429, 'PUT', 'contacts/{email}', 'contacts/busy@example.org', ['code' => 'rate_limited', 'message' => 'Too many requests.']],
            [500, 'POST', 'events', 'events', ['code' => 'server_error', 'message' => 'Server Error.']],
        ];

        $log = function (int $status, string $method, string $route, string $path, ?array $response, ?array $request) use ($faker, $tokens): void {
            $token = $tokens[$faker->boolean(50) ? 0 : 1];
            $createdAt = now()->subSeconds($faker->numberBetween(60, 12 * 86400));
            $keepBodies = $createdAt->greaterThan(now()->subDays(7)) && ! ($method === 'GET' && $status < 400);

            ApiRequest::create([
                'api_token_id' => $token->id,
                'token_name' => $token->name,
                'method' => $method,
                'route' => $route,
                'path' => $path,
                'status' => $status,
                'duration_ms' => $status === 500 ? $faker->numberBetween(800, 3000) : $faker->numberBetween(12, 240),
                'ip' => $faker->randomElement(['203.0.113.', '198.51.100.']).$faker->numberBetween(2, 250),
                'user_agent' => $token->name === 'Zapier sync' ? 'Zapier' : 'GuzzleHttp/7',
                'request_body' => $keepBodies && $request !== null ? json_encode($request) : null,
                'response_body' => $keepBodies && $response !== null ? json_encode($response) : null,
                'created_at' => $createdAt,
            ]);
        };

        foreach (range(1, 113) as $i) {
            $email = $faker->randomElement($emails);

            match (true) {
                $i % 10 < 5 => $log($faker->boolean(80) ? 200 : 201, 'PUT', 'contacts/{email}', "contacts/{$email}", ['data' => ['email' => $email, 'first_name' => null, 'last_name' => null, 'status' => 'subscribed', 'source' => 'api', 'custom_fields' => (object) [], 'tags' => ['customer'], 'lists' => [], 'unsubscribed_at' => null, 'archived_at' => null, 'created_at' => now()->subDays(30)->toIso8601String(), 'updated_at' => now()->toIso8601String()]], ['first_name' => $faker->firstName(), 'tags' => ['customer']]),
                $i % 10 < 7 => $log(202, 'POST', 'events', 'events', ['data' => ['id' => $faker->numberBetween(1000, 99999), 'type' => 'custom', 'data' => ['name' => 'order_placed', 'properties' => (object) []], 'campaign_id' => null, 'occurred_at' => now()->toIso8601String()]], ['email' => $email, 'event' => 'order_placed', 'properties' => ['total' => $faker->numberBetween(10, 400)]]),
                $i % 10 < 9 => $log(200, 'GET', 'contacts', 'contacts', null, null),
                default => $log(200, 'GET', 'me', 'me', null, null),
            };
        }

        foreach ($errors as [$status, $method, $route, $path, $response]) {
            $log($status, $method, $route, $path, $response, $method === 'GET' ? null : ['email' => $faker->randomElement($emails)]);
        }
    }

    private function createCustomEvents(Workspace $workspace): void
    {
        $faker = fake();
        $contactIds = Contact::query()->pluck('id')->all();
        $rows = [];

        foreach (range(1, 300) as $ignored) {
            $data = match ($faker->randomElement(['order_placed', 'order_placed', 'trial_started', 'page_viewed', 'page_viewed'])) {
                'order_placed' => ['name' => 'order_placed', 'properties' => ['total' => $faker->numberBetween(15, 900), 'items' => $faker->numberBetween(1, 6)]],
                'trial_started' => ['name' => 'trial_started', 'properties' => ['plan' => $faker->randomElement(self::PLANS)]],
                default => ['name' => 'page_viewed', 'properties' => ['path' => $faker->randomElement(['/pricing', '/blog', '/docs', '/features'])]],
            };

            $rows[] = [
                'workspace_id' => $workspace->id,
                'contact_id' => $faker->randomElement($contactIds),
                'campaign_id' => null,
                'campaign_recipient_id' => null,
                'type' => EventType::Custom->value,
                'data' => json_encode($data),
                'occurred_at' => now()->subMinutes($faker->numberBetween(5, 60 * 24 * 60)),
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            ActivityEvent::insert($chunk);
        }
    }

    private function emailHtml(string $heading): string
    {
        $paragraphs = collect(range(1, 2))->map(fn () => '<p>'.fake()->paragraph().'</p>')->implode('');

        return '<html><body><h1>'.e($heading).'</h1><p>Hi {{ first_name | there }},</p>'.$paragraphs
            .'<p><a href="https://demo.test/blog?utm_source=newsletter">Read the blog</a> or <a href="https://demo.test/pricing">see pricing</a>.</p>'
            .'<p><a href="{{ unsubscribe_url }}">Unsubscribe</a><br>{{ postal_address }}</p></body></html>';
    }
}
