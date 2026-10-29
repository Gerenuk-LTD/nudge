<?php

use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignSnapshot;
use Gerenuk\Marketing\Models\EmailTemplate;
use Gerenuk\Marketing\Templates\StarterTemplates;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
});

test('templates can be saved and edited', function () {
    $this->actingAs($this->owner)->post(route('marketing.templates.store'), [
        'name' => 'Monthly update',
        'subject' => 'News from us',
        'editor' => 'blocks',
        'editor_state' => ['version' => 1, 'blocks' => []],
        'html' => '<p>Hello</p>',
    ])->assertRedirect();

    $template = EmailTemplate::sole();
    expect($template->created_by)->toBe($this->owner->id);

    $this->actingAs($this->owner)->put(route('marketing.templates.update', $template), [
        'name' => 'Monthly news',
        'editor' => 'html',
        'html' => '<p>Hello again</p>',
    ])->assertRedirect(route('marketing.templates.edit', $template));

    expect($template->fresh())->name->toBe('Monthly news')->html->toBe('<p>Hello again</p>')->subject->toBeNull()
        ->editor->toBe('html')->editor_state->toBeNull();
});

test('the template list shows saved templates and the built-in starters', function () {
    EmailTemplate::factory()->create(['name' => 'Monthly update']);

    $this->actingAs($this->owner)->get(route('marketing.templates.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/templates/index')
            ->where('templates.0.name', 'Monthly update')
            ->has('starters', count(StarterTemplates::catalog())));
});

test('a new template starts from the chosen starter', function () {
    $this->actingAs($this->owner)->get(route('marketing.templates.create', ['starter' => 'newsletter']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('editor', 'blocks')
            ->where('editorState', StarterTemplates::content('newsletter')['editor_state']));
});

test('a template must say which editor made it', function () {
    $this->actingAs($this->owner)->post(route('marketing.templates.store'), ['name' => 'Mine', 'editor' => 'word', 'html' => '<p>x</p>'])
        ->assertSessionHasErrors('editor');
});

test('a campaign copies a saved template, and later edits to the template leave it alone', function () {
    $template = EmailTemplate::factory()->create(['subject' => 'News from us', 'preheader' => 'This month', 'html' => '<p>Original</p>']);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.store'), [
        'name' => 'November',
        'template' => "saved:{$template->id}",
    ])->assertRedirect();

    $template->update(['html' => '<p>Changed</p>']);

    expect(Campaign::sole())->subject->toBe('News from us')->preheader->toBe('This month')->html->toBe('<p>Original</p>');
});

test('a campaign starts from a starter, or the simple one when none is chosen', function (?string $choice, string $starter) {
    $this->actingAs($this->owner)->post(route('marketing.campaigns.store'), ['name' => 'November', 'template' => $choice])->assertRedirect();

    $content = StarterTemplates::content($starter);
    expect(Campaign::sole())->editor->toBe($content['editor'])->editor_state->toBe($content['editor_state'])->html->toBe($content['html']);
})->with([
    'a builder starter' => ['starter:announcement', 'announcement'],
    'the HTML starter' => ['starter:custom', 'custom'],
    'nothing chosen' => [null, 'simple'],
]);

test('a campaign keeps the builder document alongside its HTML', function () {
    $campaign = Campaign::factory()->create();
    $document = ['version' => 1, 'settings' => [], 'blocks' => [['id' => 'a', 'type' => 'text', 'html' => '<p>Hi</p>', 'align' => 'left']]];

    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['editor' => 'blocks', 'editor_state' => $document, 'html' => '<p>Hi</p>'])
        ->assertSessionHasNoErrors();

    expect($campaign->fresh())->editor->toBe('blocks')->editor_state->toBe($document);

    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['ab_editor_state_b' => $document, 'ab_html_b' => '<p>Hi B</p>'])
        ->assertSessionHasNoErrors();

    expect($campaign->fresh()->ab_editor_state_b)->toBe($document);

    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['editor' => 'word'])->assertSessionHasErrors('editor');
});

test('a campaign cannot start from an unknown template or one from another workspace', function (string $choice) {
    $this->actingAs($this->owner)->post(route('marketing.campaigns.store'), ['name' => 'November', 'template' => $choice])
        ->assertSessionHasErrors('template');

    expect(Campaign::count())->toBe(0);
})->with([
    'unknown starter' => ['starter:missing'],
    'nonsense' => ['saved:abc'],
    'another workspace' => [function () {
        $this->createOwner('Globex');
        $foreign = EmailTemplate::factory()->create();
        $this->useWorkspace($this->workspace);

        return "saved:{$foreign->id}";
    }],
]);

test('templates from another workspace cannot be opened or changed', function () {
    $this->createOwner('Globex');
    $foreign = EmailTemplate::factory()->create();
    $this->useWorkspace($this->workspace);

    $this->actingAs($this->owner)->get(route('marketing.templates.edit', $foreign))->assertNotFound();
    $this->actingAs($this->owner)->delete(route('marketing.templates.destroy', $foreign))->assertNotFound();
});

test('a sent campaign is saved as a template from what was actually sent', function () {
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sent, 'subject' => 'Edited later', 'html' => '<p>Edited later</p>']);
    CampaignSnapshot::create([
        'campaign_id' => $campaign->id, 'subject' => 'As sent', 'preheader' => null, 'from_name' => 'Acme', 'from_email' => 'hi@acme.test',
        'html' => '<p>As sent</p>', 'text' => 'As sent',
    ]);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.template', $campaign), ['name' => 'From October'])->assertRedirect();

    expect(EmailTemplate::sole())->name->toBe('From October')->subject->toBe('As sent')->html->toBe('<p>As sent</p>');
});

test('duplicating a template makes an independent copy', function () {
    $template = EmailTemplate::factory()->create(['name' => 'Monthly update']);

    $this->actingAs($this->owner)->post(route('marketing.templates.duplicate', $template))->assertRedirect();

    expect(EmailTemplate::latest('id')->first())->name->toBe('Monthly update (copy)')->html->toBe($template->html);
});

test('viewers can browse templates but not change them', function () {
    $viewer = $this->addMember($this->workspace, 'Viewer');
    $template = EmailTemplate::factory()->create();

    $this->actingAs($viewer)->get(route('marketing.templates.index'))->assertOk();
    $this->actingAs($viewer)->post(route('marketing.templates.store'), ['name' => 'Mine', 'editor' => 'html', 'html' => '<p>x</p>'])->assertForbidden();
    $this->actingAs($viewer)->get(route('marketing.templates.edit', $template))->assertForbidden();
});

test('the campaign and automation editors load templates only when asked', function () {
    EmailTemplate::factory()->create(['name' => 'Monthly update']);
    $automation = Automation::factory()->create();

    foreach ([route('marketing.campaigns.index'), route('marketing.automations.edit', $automation)] as $url) {
        $this->actingAs($this->owner)->get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('templates')
            ->reloadOnly('templates', fn (AssertableInertia $reload) => $reload
                ->where('templates.0.key', 'saved:'.EmailTemplate::sole()->id)
                ->has('templates', 1 + count(StarterTemplates::catalog()))));
    }
});
