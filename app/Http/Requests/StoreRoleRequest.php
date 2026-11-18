<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Services\PermissionService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or edit a role (ISSUE-26).
 *
 * Fields: `name`, `color` (#rrggbb) with `no_color` to clear it, and
 * `permissions[]`, the App\Enums\Permission values to turn on (anything not
 * listed is turned off). For @everyone only `permissions` is used.
 */
class StoreRoleRequest extends FormRequest
{
    /**
     * ServerPolicy::manageRoles, and for an existing role RolePolicy::manage
     * (it must be below the actor's highest role, ISSUE-27).
     */
    public function authorize(): bool
    {
        $server = $this->route('server');
        $user = $this->user();

        if (! $server instanceof Server || $user === null || ! $user->can('manageRoles', $server)) {
            return false;
        }

        $role = $this->route('role');

        return ! $role instanceof Role || $user->can('manage', $role);
    }

    /**
     * Like Discord, a member who is not the owner can only turn on or off the
     * flags they have themselves (administrators have them all).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $server = $this->route('server');
                $user = $this->user();
                if (! $server instanceof Server || $user === null || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $requested = 0;
                foreach ((array) $this->input('permissions', []) as $value) {
                    $requested |= is_numeric($value) ? (int) $value : 0;
                }

                $role = $this->route('role');
                $current = $role instanceof Role ? (int) $role->permissions : 0;
                $changed = ($requested ^ $current) & Permission::all();
                $missing = $changed & ~app(PermissionService::class)->permissions($user, $server);

                if ($missing !== 0) {
                    $labels = array_map(fn (Permission $permission): string => $permission->label(), Permission::fromMask($missing));
                    $validator->errors()->add('permissions', 'You can only change permissions you have yourself: '.implode(', ', $labels).'.');
                }
            },
        ];
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
