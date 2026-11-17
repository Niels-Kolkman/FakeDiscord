<?php

namespace App\Models;

use Database\Factories\ChannelCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A collapsible group of channels in a server's sidebar.
 */
class ChannelCategory extends Model
{
    /** @use HasFactory<ChannelCategoryFactory> */
    use HasFactory;

    public const MAX_NAME_LENGTH = 100;

    /** Discord allows 50 categories per server. */
    public const MAX_PER_SERVER = 50;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'server_id',
        'name',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return HasMany<Channel, $this>
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class, 'category_id')->orderBy('position')->orderBy('id');
    }
}
