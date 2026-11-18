<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Message search within one server (ISSUE-21), newest first.
 *
 * The text goes through Laravel Scout's database engine (Message::search()),
 * which is a LIKE '%text%' on messages.content. Scout does not escape "%"
 * and "_" in the text, so on top of that we add our own LIKE with ESCAPE,
 * which makes "100%" or "snake_case" match only themselves. Like every
 * LIKE on SQLite, it ignores case for ASCII letters only.
 *
 * Filters: channel (of this server), author (user id) and a date range
 * (whole days in the app timezone, both ends included). Messages in other
 * servers, in DMs and soft deleted messages are never found.
 */
final readonly class MessageSearch
{
    public const PER_PAGE = 25;

    public const MAX_QUERY_LENGTH = 200;

    public function __construct(
        public ?string $text = null,
        public ?int $channelId = null,
        public ?int $authorId = null,
        public ?Carbon $from = null,
        public ?Carbon $to = null,
    ) {}

    /**
     * Without text or any filter there is nothing to search for.
     */
    public function isEmpty(): bool
    {
        return $this->text === null
            && $this->channelId === null
            && $this->authorId === null
            && $this->from === null
            && $this->to === null;
    }

    /**
     * @return LengthAwarePaginator<int, Message>
     */
    public function paginate(Server $server, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $search = Message::search($this->text ?? '')
            ->query(function (Builder $query) use ($server): void {
                /** @var Builder<Message> $query */
                $query
                    ->whereIn('messages.channel_id', Channel::query()->select('id')->where('server_id', $server->id))
                    ->when($this->text !== null, fn (Builder $query) => $query->whereRaw(
                        "messages.content LIKE ? ESCAPE '!'",
                        ['%'.self::escapeLike((string) $this->text).'%'],
                    ))
                    ->when($this->channelId !== null, fn (Builder $query) => $query->where('messages.channel_id', $this->channelId))
                    ->when($this->authorId !== null, fn (Builder $query) => $query->where('messages.user_id', $this->authorId))
                    ->when($this->from !== null, fn (Builder $query) => $query->where('messages.created_at', '>=', $this->from?->copy()->startOfDay()))
                    ->when($this->to !== null, fn (Builder $query) => $query->where('messages.created_at', '<', $this->to?->copy()->addDay()->startOfDay()))
                    ->with(['author', 'channel']);
            });

        /** @var LengthAwarePaginator<int, Message> $page */
        $page = $search->paginate($perPage);

        return $page;
    }

    /**
     * Escape LIKE wildcards with "!" (the ESCAPE character above).
     */
    public static function escapeLike(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }

    /**
     * The content as safe HTML, with every match of $text (ignoring case)
     * in a <mark>. Everything is escaped, the matches included.
     */
    public static function highlight(string $content, ?string $text): HtmlString
    {
        if ($text === null || $text === '') {
            return new HtmlString(e($content));
        }

        $parts = preg_split('/('.preg_quote($text, '/').')/iu', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return new HtmlString(e($content));
        }

        $html = '';
        foreach ($parts as $index => $part) {
            // With DELIM_CAPTURE the matches are at the odd indexes.
            $html .= $index % 2 === 1
                ? '<mark class="rounded-sm bg-[#f0b232]/40 text-inherit">'.e($part).'</mark>'
                : e($part);
        }

        return new HtmlString($html);
    }
}
