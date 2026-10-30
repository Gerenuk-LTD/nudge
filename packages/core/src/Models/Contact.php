<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Database\Factories\ContactFactory;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A person in a workspace's audience, unique by lowercased email.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property array<string, mixed>|null $custom_fields
 * @property ContactStatus $status
 * @property ContactSource $source
 * @property CarbonInterface|null $unsubscribed_at
 * @property CarbonInterface|null $archived_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
#[Fillable(['workspace_id', 'email', 'first_name', 'last_name', 'custom_fields', 'status', 'source', 'unsubscribed_at', 'archived_at'])]
#[UseFactory(ContactFactory::class)]
class Contact extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'subscribed',
        'source' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'status' => ContactStatus::class,
            'source' => ContactSource::class,
            'unsubscribed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => self::normalizeEmail($value));
    }

    /**
     * @return BelongsToMany<ContactList, $this>
     */
    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(ContactList::class, 'contact_list_memberships')
            ->withPivot('created_at');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * @return HasMany<CampaignRecipient, $this>
     */
    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * @return HasMany<ActivityEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forEmail(Builder $query, string $email): void
    {
        $query->where('email', self::normalizeEmail($email));
    }
}
