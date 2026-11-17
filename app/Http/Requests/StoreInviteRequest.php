<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Options for a new invite, picked from fixed lists like Discord does.
 */
class StoreInviteRequest extends FormRequest
{
    /** Minutes until expiry => label. 0 = never. */
    public const EXPIRY_OPTIONS = [
        30 => '30 minutes',
        60 => '1 hour',
        360 => '6 hours',
        720 => '12 hours',
        1440 => '1 day',
        10080 => '7 days',
        0 => 'Never',
    ];

    public const DEFAULT_EXPIRY = 10080;

    /** Maximum uses => label. 0 = no limit. */
    public const MAX_USES_OPTIONS = [
        0 => 'No limit',
        1 => '1 use',
        5 => '5 uses',
        10 => '10 uses',
        25 => '25 uses',
        50 => '50 uses',
        100 => '100 uses',
    ];

    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server
            && ($this->user()?->can('createInvite', $server) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expires_in' => ['required', 'integer', Rule::in(array_keys(self::EXPIRY_OPTIONS))],
            'max_uses' => ['required', 'integer', Rule::in(array_keys(self::MAX_USES_OPTIONS))],
        ];
    }

    /**
     * Minutes until expiry, or null for never.
     */
    public function expiresInMinutes(): ?int
    {
        return $this->integer('expires_in') ?: null;
    }

    /**
     * Maximum uses, or null for unlimited.
     */
    public function maxUses(): ?int
    {
        return $this->integer('max_uses') ?: null;
    }
}
