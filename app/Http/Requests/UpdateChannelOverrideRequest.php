<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\ChannelPermissionOverride;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One role's override in one channel:
 *
 *     permissions[ViewChannels]=allow&permissions[SendMessages]=inherit
 *
 * Every Permission::channelOverridable() flag must be given, as allow,
 * inherit or deny. Who may send it is ChannelPermissionController.
 */
class UpdateChannelOverrideRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $states = [ChannelPermissionOverride::ALLOW, ChannelPermissionOverride::INHERIT, ChannelPermissionOverride::DENY];
        $names = array_map(fn (Permission $permission): string => $permission->name, Permission::channelOverridable());

        $rules = ['permissions' => ['required', 'array:'.implode(',', $names)]];
        foreach ($names as $name) {
            $rules["permissions.{$name}"] = ['required', 'string', Rule::in($states)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.*.required' => 'Choose allow, inherit or deny for every permission.',
            'permissions.*.in' => 'Choose allow, inherit or deny for every permission.',
        ];
    }

    /**
     * @return array<string, string> Permission case name => state
     */
    public function states(): array
    {
        /** @var array<string, string> $states */
        $states = $this->validated('permissions');

        return $states;
    }
}
