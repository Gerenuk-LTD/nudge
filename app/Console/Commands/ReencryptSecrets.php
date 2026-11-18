<?php

namespace App\Console\Commands;

use App\Models\User;
use Gerenuk\Marketing\Models\Provider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts everything stored under an old APP_KEY with the current one, so the old key can be dropped from
 * APP_PREVIOUS_KEYS after a leak. Safe to run more than once.
 *
 * Any new `encrypted` cast must be added here.
 */
#[Signature('app:reencrypt-secrets')]
#[Description('Re-encrypt stored secrets with the current APP_KEY, so old keys can be removed')]
class ReencryptSecrets extends Command
{
    public function handle(): int
    {
        $providers = $this->reencryptProviders();

        if ($providers === null) {
            return self::FAILURE;
        }

        $users = $this->reencryptUsers();

        if ($users === null) {
            return self::FAILURE;
        }

        $this->components->info("Re-encrypted {$providers} providers and {$users} users' two-factor settings.");

        return self::SUCCESS;
    }

    /**
     * Eloquent compares decrypted values and sees no change on save(), so the new ciphertext is written directly.
     * Both casts store encryptString() of the value (of its JSON for the array cast), so the raw column is decrypted
     * and encrypted again as a string without parsing it.
     */
    private function reencryptProviders(): ?int
    {
        $updates = [];

        foreach (Provider::withoutGlobalScopes()->lazyById() as $provider) {
            try {
                $values = [];

                foreach (['credentials', 'webhook_secret'] as $column) {
                    $raw = $provider->getRawOriginal($column);

                    $values[$column] = $raw === null ? null : Crypt::encryptString(Crypt::decryptString($raw));
                }
            } catch (DecryptException) {
                $this->components->error("A secret on providers row {$provider->id} can't be decrypted with the current or previous keys. Nothing was changed for providers.");

                return null;
            }

            $updates[$provider->id] = $values;
        }

        DB::transaction(function () use ($updates): void {
            foreach ($updates as $id => $values) {
                DB::table('providers')->where('id', $id)->update($values);
            }
        });

        return count($updates);
    }

    /**
     * Fortify writes these with encrypt(), which serializes, unlike the Eloquent casts.
     */
    private function reencryptUsers(): ?int
    {
        $updates = [];

        foreach (User::query()->whereNotNull('two_factor_secret')->lazyById() as $user) {
            try {
                $values = ['two_factor_secret' => encrypt(decrypt($user->getRawOriginal('two_factor_secret')))];

                $codes = $user->getRawOriginal('two_factor_recovery_codes');

                if ($codes !== null) {
                    $values['two_factor_recovery_codes'] = encrypt(decrypt($codes));
                }
            } catch (DecryptException) {
                $this->components->error("A two-factor value on users row {$user->id} can't be decrypted with the current or previous keys. Nothing was changed for users.");

                return null;
            }

            $updates[$user->id] = $values;
        }

        DB::transaction(function () use ($updates): void {
            foreach ($updates as $id => $values) {
                DB::table('users')->where('id', $id)->update($values);
            }
        });

        return count($updates);
    }
}
