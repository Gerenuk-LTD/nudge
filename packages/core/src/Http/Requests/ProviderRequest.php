<?php

namespace Gerenuk\Nudge\Http\Requests;

use Closure;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Sending\ProviderType;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(CurrentWorkspace $currentWorkspace): array
    {
        $provider = $this->route('provider');
        $existing = $provider instanceof Provider ? $provider : null;
        $type = $existing === null ? ProviderType::tryFrom((string) $this->input('type')) : $existing->type;

        $rules = [
            'name' => ['required', 'string', 'max:100', Rule::unique('providers', 'name')->where('workspace_id', $currentWorkspace->id())->ignore($existing?->id)],
            'type' => [$existing ? 'prohibited' : 'required', Rule::enum(ProviderType::class)],
            'credentials' => ['required', 'array'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'from_email' => ['nullable', 'email:filter', 'max:254'],
            'reply_to' => ['nullable', 'email:filter', 'max:254'],
            'max_per_second' => ['required', 'integer', 'min:1', 'max:1000'],
            'daily_limit' => ['nullable', 'integer', 'min:1'],
            'webhook_secret' => ['nullable', 'string', 'max:2000'],
        ];

        foreach ($type?->fields() ?? [] as $field) {
            // Saved secrets are not sent back to the browser, so blank means "keep".
            $required = $field->required && ! ($existing && $field->secret);

            $rules['credentials.'.$field->key] = [
                $required ? 'required' : 'nullable',
                'string',
                'max:1000',
                ...($field->options === [] ? [] : [Rule::in(array_column($field->options, 'value'))]),
            ];

            if ($type === ProviderType::Smtp && $field->key === 'host' && ! config('nudge.sending.allow_private_smtp_hosts')) {
                $rules['credentials.host'][] = function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! $this->resolvesToPublicAddresses($value)) {
                        $fail(__('This host is on a private network, which this installation doesn\'t allow.'));
                    }
                };
            }

            // Region codes end up in the API hostname, so free text could point sending at any host.
            if ($field->key === 'region' && $type === ProviderType::AmazonSes) {
                $rules['credentials.region'][] = 'regex:/^[a-z]{2}(-[a-z]+)+-\d+$/';
            }

            if ($field->key === 'region' && $type === ProviderType::Scaleway) {
                $rules['credentials.region'][] = Rule::in(['fr-par', 'nl-ams', 'pl-waw']);
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credentials.region.regex' => __('Enter a region code such as eu-west-1.'),
        ];
    }

    /**
     * Whether the host resolves, and every address it resolves to is public.
     */
    private function resolvesToPublicAddresses(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $addresses = gethostbynamel($host) ?: [];

            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
