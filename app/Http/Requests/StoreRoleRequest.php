<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\Server;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or edit a role (ISSUE-26).
 *
 * Fields: `name`, `color` (#rrggbb) with `no_color` to clear it, and
 * `permissions[]`, the App\Enums\Permission values to turn on (anything not
 * listed is turned off). For @everyone only `permissions` is used.
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server
            && ($this->user()?->can('manageRoles', $server) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $permissions = [
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['integer', Rule::in(array_column(Permission::cases(), 'value'))],
        ];

        if ($this->isDefaultRole()) {
            return $permissions;
        }

        return $permissions + [
            'name' => [
                'required',
                'string',
                'max:'.Role::MAX_NAME_LENGTH,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && mb_strtolower($value) === Role::EVERYONE) {
                        $fail('The name '.Role::EVERYONE.' is reserved.');
                    }
                },
            ],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'no_color' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'color.regex' => 'The colour must be a hex colour like #5865f2.',
            'permissions.*' => 'Unknown permission.',
        ];
    }

    public function roleName(): string
    {
        return $this->string('name')->value();
    }

    /**
     * Lower case #rrggbb, or null for "no colour".
     */
    public function color(): ?string
    {
        $color = $this->input('color');

        return $this->boolean('no_color') || ! is_string($color) || $color === ''
            ? null
            : strtolower($color);
    }

    public function permissionMask(): int
    {
        /** @var array<int|string> $values */
        $values = $this->validated('permissions', []);

        return array_reduce($values, fn (int $mask, int|string $value): int => $mask | (int) $value, 0);
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        $this->merge([
            'name' => is_string($name) ? (string) preg_replace('/\s+/u', ' ', trim($name)) : $name,
        ]);
    }

    private function isDefaultRole(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role && $role->is_default;
    }
}
