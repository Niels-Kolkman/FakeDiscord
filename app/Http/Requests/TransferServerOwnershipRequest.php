<?php

namespace App\Http\Requests;

use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Hand a server to another member. The owner types the server name again to
 * confirm, like deleting it.
 */
class TransferServerOwnershipRequest extends FormRequest
{
    /**
     * Own bag, because the delete form on the same page also has confirm_name.
     */
    public const ERROR_BAG = 'transferOwnership';

    /**
     * @var string
     */
    protected $errorBag = self::ERROR_BAG;

    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server
            && ($this->user()?->can('transferOwnership', $server) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $server = $this->targetServer();

        return [
            'new_owner_id' => [
                'required',
                'integer',
                Rule::notIn([$server->owner_id]),
                Rule::exists('server_members', 'user_id')->where('server_id', $server->getKey()),
            ],
            'confirm_name' => ['required', 'string', Rule::in([$server->name])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'new_owner_id.required' => 'Choose the member who will become the owner.',
            'new_owner_id.integer' => 'Choose the member who will become the owner.',
            'new_owner_id.not_in' => 'You already own this server. Choose another member.',
            'new_owner_id.exists' => 'The new owner must be a member of this server.',
            'confirm_name.required' => 'Type the server name exactly to confirm.',
            'confirm_name.string' => 'Type the server name exactly to confirm.',
            'confirm_name.in' => 'Type the server name exactly to confirm.',
        ];
    }

    public function targetServer(): Server
    {
        /** @var Server */
        return $this->route('server');
    }

    public function newOwner(): User
    {
        return User::query()->findOrFail($this->integer('new_owner_id'));
    }
}
