<?php

use Gerenuk\Marketing\Models\EmailAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    [$this->owner, $this->workspace] = $this->createOwner();
});

test('an image can be uploaded for emails and comes back with a public address', function () {
    $response = $this->actingAs($this->owner)->postJson(route('marketing.email-assets.store'), [
        'file' => UploadedFile::fake()->image('team.png', 800, 600),
    ]);

    $asset = EmailAsset::sole();
    $response->assertCreated()->assertJson(['asset' => [
        'id' => $asset->id,
        'url' => Storage::disk('public')->url($asset->path),
        'name' => 'team.png',
        'width' => 800,
        'height' => 600,
    ]]);
    Storage::disk('public')->assertExists($asset->path);
    expect($asset->path)->toStartWith("email-assets/{$this->workspace->id}/")
        ->and($asset->uploaded_by)->toBe($this->owner->id);
});

test('only common image formats can be uploaded', function (UploadedFile $file) {
    $this->actingAs($this->owner)->postJson(route('marketing.email-assets.store'), ['file' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(EmailAsset::count())->toBe(0);
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'pdf' => fn () => UploadedFile::fake()->create('brochure.pdf', 10, 'application/pdf'),
    'too large' => fn () => UploadedFile::fake()->image('huge.png')->size(6000),
]);

test('the image picker lists only this workspace\'s uploads', function () {
    $this->actingAs($this->owner)->postJson(route('marketing.email-assets.store'), ['file' => UploadedFile::fake()->image('ours.png')]);

    [$otherOwner] = $this->createOwner('Globex');
    $this->actingAs($otherOwner)->postJson(route('marketing.email-assets.store'), ['file' => UploadedFile::fake()->image('theirs.png')]);

    $this->actingAs($this->owner)->getJson(route('marketing.email-assets.index'))
        ->assertOk()
        ->assertJsonCount(1, 'assets')
        ->assertJsonPath('assets.0.name', 'ours.png');
});

test('people who cannot edit emails cannot upload images', function () {
    $viewer = $this->addMember($this->workspace, 'Viewer');

    $this->actingAs($viewer)->postJson(route('marketing.email-assets.store'), ['file' => UploadedFile::fake()->image('a.png')])->assertForbidden();
    $this->actingAs($viewer)->getJson(route('marketing.email-assets.index'))->assertForbidden();
});
