<?php

namespace Database\Seeders;

use App\Models\User;
use Gerenuk\Marketing\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Marketing\Enums\AudienceType;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\CustomFieldType;
use Gerenuk\Marketing\Enums\SuppressionReason;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\EmailTemplate;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Models\Tag;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Templates\EmailEditor;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A small workspace for a first real send: a handful of contacts that all deliver to inboxes you own (using
 * plus addressing, so nothing bounces), tags, a list, a segment, a builder template and a draft campaign.
 * Two of the contacts are unsubscribed or suppressed and must not receive anything. No provider is added and
 * nothing is sent.
 *
 * Run with `php artisan db:seed --class=SendTestSeeder`.
 */
class SendTestSeeder extends Seeder
{
    private const WORKSPACE = 'Send test';

    public function run(CreateWorkspace $createWorkspace, CurrentWorkspace $currentWorkspace): void
    {
        $user = $this->owner();
        $inbox = $this->askEmail('Which inbox should the test emails go to? Each contact is a +alias of it');
        $otherInboxes = $this->askOtherInboxes();
        $postalAddress = $this->command->ask('Postal address for the footer (anti-spam law requires a real one)');

        if (blank($postalAddress)) {
            throw new InvalidArgumentException('A postal address is required to send.');
        }

        if ($user->workspaces()->where('name', self::WORKSPACE)->exists()) {
            $this->command->warn("{$user->email} already has a \"".self::WORKSPACE.'" workspace. Delete it first to seed again.');

            return;
        }

        $workspace = $createWorkspace->handle($user, self::WORKSPACE);
        $workspace->update(['postal_address' => $postalAddress]);

        $currentWorkspace->runAs($workspace, function () use ($user, $inbox, $otherInboxes): void {
            DB::transaction(function () use ($user, $inbox, $otherInboxes): void {
                $list = $this->createAudience($inbox, $otherInboxes);
                $this->createEmails($user, $list);
            });
        });

        $user->switchWorkspace($workspace);

        $this->command->info('Created the "'.self::WORKSPACE."\" workspace for {$user->email}.");
        $this->command->line('Next: connect a provider, then open the "First send" campaign, choose the provider and a from address, and save.');
    }

    private function owner(): User
    {
        $email = $this->command->ask('Which user should own the workspace?', 'test@example.com');
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        if (app()->isProduction()) {
            throw new InvalidArgumentException("No user with the email {$email}. Register first.");
        }

        $user = User::query()->create(['name' => 'Test User', 'email' => $email, 'password' => 'password']);
        $user->markEmailAsVerified();

        return $user;
    }

    private function askEmail(string $question): string
    {
        $email = trim((string) $this->command->ask($question));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("\"{$email}\" is not an email address.");
        }

        return $email;
    }

    /**
     * Inboxes at other services, to compare placement between them. Each becomes one contact.
     *
     * @return list<string>
     */
    private function askOtherInboxes(): array
    {
        $answer = (string) $this->command->ask('Other inboxes to compare, such as Outlook or iCloud (comma separated, optional)', '');

        return collect(explode(',', $answer))
            ->map(fn (string $email) => trim($email))
            ->filter()
            ->each(function (string $email): void {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new InvalidArgumentException("\"{$email}\" is not an email address.");
                }
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $otherInboxes
     */
    private function createAudience(string $inbox, array $otherInboxes): ContactList
    {
        CustomFieldDefinition::create(['key' => 'company', 'label' => 'Company', 'type' => CustomFieldType::Text]);
        CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => CustomFieldType::Text]);

        $tags = collect(['customer', 'lead', 'vip', 'beta', 'other inbox'])
            ->mapWithKeys(fn (string $name) => [$name => Tag::create(['name' => $name])->id]);

        $list = ContactList::create(['name' => 'Send test', 'description' => 'Everyone in the first real send.']);

        [$local, $domain] = explode('@', $inbox, 2);
        $alias = fn (string $suffix): string => "{$local}+{$suffix}@{$domain}";

        $contacts = [
            [$alias('alex'), 'Alex', 'Morgan', ['company' => 'Northwind', 'plan' => 'pro'], ['customer', 'vip'], ContactStatus::Subscribed],
            [$alias('sam'), 'Sam', 'Patel', ['company' => 'Acme', 'plan' => 'free'], ['lead'], ContactStatus::Subscribed],
            [$alias('jordan'), 'Jordan', 'Lee', ['company' => 'Globex', 'plan' => 'starter'], ['customer', 'beta'], ContactStatus::Subscribed],
            // No name or company, so the merge tag fallbacks show.
            [$alias('noname'), null, null, [], ['lead'], ContactStatus::Subscribed],
            // These two are in the list but must not receive the campaign.
            [$alias('unsubscribed'), 'Una', 'Subscribed', ['company' => 'Initech'], ['lead'], ContactStatus::Unsubscribed],
            [$alias('suppressed'), 'Sue', 'Pressed', ['company' => 'Umbrella'], ['customer'], ContactStatus::Subscribed],
            ...array_map(fn (string $email) => [$email, null, null, [], ['other inbox'], ContactStatus::Subscribed], $otherInboxes),
        ];

        foreach ($contacts as [$email, $firstName, $lastName, $fields, $contactTags, $status]) {
            $contact = Contact::create([
                'email' => Contact::normalizeEmail($email),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'custom_fields' => $fields,
                'status' => $status,
                'source' => ContactSource::Manual,
                'unsubscribed_at' => $status === ContactStatus::Unsubscribed ? now() : null,
            ]);

            $contact->tags()->attach($tags->only($contactTags)->values()->all());
            $contact->lists()->attach($list->id, ['created_at' => now()]);
        }

        Suppression::suppress($alias('suppressed'), SuppressionReason::Manual, 'Seeded to check suppressed contacts are skipped.');

        Segment::create(['name' => 'VIPs', 'definition' => ['match' => 'all', 'conditions' => [
            ['type' => 'condition', 'field' => 'tag', 'operator' => 'has', 'value' => $tags['vip']],
        ]]]);

        return $list;
    }

    /**
     * Builder emails get their HTML when the builder first renders them, so the campaign has none until it is
     * opened and saved.
     */
    private function createEmails(User $user, ContactList $list): void
    {
        EmailTemplate::create([
            'created_by' => $user->id,
            'name' => 'Send test',
            'description' => 'Merge tags, links and a button, for checking a real send.',
            'subject' => 'Testing, testing, {{ first_name | friend }}',
            'preheader' => 'Checking merge tags, tracking and unsubscribe.',
            'editor' => EmailEditor::BLOCKS,
            'editor_state' => $this->document(),
            'html' => '',
        ]);

        Campaign::create([
            'created_by' => $user->id,
            'name' => 'First send',
            'subject' => 'Testing, testing, {{ first_name | friend }}',
            'preheader' => 'Checking merge tags, tracking and unsubscribe.',
            'from_name' => 'Send test',
            'editor' => EmailEditor::BLOCKS,
            'editor_state' => $this->document(),
            'audience_type' => AudienceType::Lists,
            'list_ids' => [$list->id],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $text = fn (string $id, string $html): array => ['id' => $id, 'type' => 'text', 'html' => $html, 'align' => 'left'];

        return [
            'version' => 1,
            'settings' => [
                'backdrop' => '#f4f4f5',
                'canvas' => '#ffffff',
                'width' => 600,
                'font' => 'Arial, Helvetica, sans-serif',
                'text' => '#18181b',
                'link' => '#2563eb',
            ],
            'blocks' => [
                ['id' => 'title', 'type' => 'heading', 'html' => 'This is a test send', 'level' => 1, 'align' => 'left'],
                $text('greeting', '<p>Hi {{ first_name | there }},</p><p>This email checks that a real send works from start to finish. You are receiving it at {{ email }}.</p>'),
                ['id' => 'checks', 'type' => 'heading', 'html' => 'What to check', 'level' => 3, 'align' => 'left'],
                $text('merge', '<p>Company: <strong>{{ custom.company | not set }}</strong>. Plan: <strong>{{ custom.plan | not set }}</strong>. Contacts without these show "not set".</p>'),
                $text('links', '<p>Click <a href="https://example.com/?utm_source=send-test">this link</a> or the button below, then check the campaign report counts the click.</p>'),
                ['id' => 'cta', 'type' => 'button', 'label' => 'Record a click', 'url' => 'https://example.org/', 'align' => 'left', 'background' => '#18181b', 'color' => '#ffffff'],
                $text('unsubscribe', '<p>Finally, try the unsubscribe link in the footer on one of the aliases.</p>'),
                ['id' => 'rule', 'type' => 'divider'],
                ['id' => 'footer', 'type' => 'footer', 'html' => '<p>You are receiving this because you are testing the marketing platform.</p>', 'align' => 'left'],
            ],
        ];
    }
}
