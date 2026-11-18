<?php

use App\Models\User;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Sending\ProviderType;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

function newKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function useKeys(string $key, array $previous = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);

    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

/**
 * Creates a provider and a user with two-factor settings under the current key.
 *
 * @return array{provider: Provider, user: User}
 */
function secretsUnderCurrentKey(): array
{
    $provider = Provider::create([
        'workspace_id' => Workspace::factory()->create()->id,
        'name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 100,
        'credentials' => ['host' => 'smtp.test', 'port' => '587', 'encryption' => 'starttls'],
        'webhook_secret' => 'whsec_original',
    ]);

    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt('TWOFACTORSECRET'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-one', 'code-two'])),
    ])->save();

    return ['provider' => $provider, 'user' => $user];
}

test('secrets survive dropping the old key', function () {
    $old = newKey();
    $new = newKey();

    useKeys($old);
    ['provider' => $provider, 'user' => $user] = secretsUnderCurrentKey();

    useKeys($new, [$old]);

    expect(Provider::withoutGlobalScopes()->find($provider->id)->webhook_secret)->toBe('whsec_original');

    $this->artisan('app:reencrypt-secrets')->assertSuccessful();

    useKeys($new);

    $provider = Provider::withoutGlobalScopes()->find($provider->id);
    $user = $user->fresh();

    expect($provider->credentials)->toBe(['host' => 'smtp.test', 'port' => '587', 'encryption' => 'starttls'])
        ->and($provider->webhook_secret)->toBe('whsec_original')
        ->and(decrypt($user->two_factor_secret))->toBe('TWOFACTORSECRET')
        ->and(json_decode(decrypt($user->two_factor_recovery_codes), true))->toBe(['code-one', 'code-two']);
});

test('without the command, dropping the old key loses the secrets', function () {
    $old = newKey();
    $new = newKey();

    useKeys($old);
    ['provider' => $provider] = secretsUnderCurrentKey();

    useKeys($new);

    expect(fn () => Provider::withoutGlobalScopes()->find($provider->id)->credentials)->toThrow(DecryptException::class);
});

test('a row that cannot be decrypted fails cleanly', function () {
    ['provider' => $provider] = secretsUnderCurrentKey();
    $original = DB::table('providers')->where('id', $provider->id)->value('credentials');

    DB::table('providers')->where('id', $provider->id)->update(['credentials' => 'garbage-not-ciphertext']);

    $this->artisan('app:reencrypt-secrets')
        ->expectsOutputToContain("providers row {$provider->id}")
        ->doesntExpectOutputToContain('garbage-not-ciphertext')
        ->assertFailed();

    expect($original)->not->toBe('garbage-not-ciphertext')
        ->and(DB::table('providers')->where('id', $provider->id)->value('credentials'))->toBe('garbage-not-ciphertext');
});
