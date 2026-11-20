<?php

namespace App\Console\Commands;

use App\Actions\Channels\MarkChannelRead;
use App\Actions\Messages\SendMessage;
use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * A simple multi-user load test for SQLite (ISSUE-42):
 * `php artisan app:load-test --users=8 --messages=25`.
 *
 * On a throwaway database (a temp SQLite file with the same settings as
 * the real one: WAL, busy_timeout, IMMEDIATE transactions) it runs --users
 * processes at once, like that many people chatting in one channel, next
 * to one queue worker. Each sends --messages messages through
 * App\Actions\Messages\SendMessage (every fifth mentions someone, so a
 * mention notification is written too), reads the history and marks the
 * channel read, while the worker takes the queued broadcasts off the jobs
 * table.
 *
 * It fails when any of them hit "database is locked" (or any other error),
 * when messages went missing, or when jobs are left behind. The app's own
 * database is not touched.
 */
class LoadTest extends Command
{
    protected $signature = 'app:load-test
        {--users=8 : People sending at the same time}
        {--messages=25 : Messages each of them sends}
        {--keep : Keep the temp database}
        {--untuned : Use SQLite\'s defaults instead (no WAL, no busy timeout, DEFERRED) to compare}
        {--role= : Internal: prepare or send}
        {--user= : Internal}
        {--channel= : Internal}
        {--recipient= : Internal}';

    protected $description = 'Send messages from several processes at once and report SQLite lock errors';

    public function handle(): int
    {
        return match ($this->option('role')) {
            'prepare' => $this->prepare(),
            'send' => $this->send(),
            default => $this->runLoadTest(),
        };
    }

    private function runLoadTest(): int
    {
        $users = max(2, (int) $this->option('users'));
        $messages = max(1, (int) $this->option('messages'));
        $database = storage_path('framework/load-test-'.Str::random(8).'.sqlite');
        touch($database);

        $env = [
            'APP_ENV' => 'local',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_URL' => '',
            'QUEUE_CONNECTION' => 'database',
            // No Reverb needed: the worker still reserves and deletes every job.
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'MAIL_MAILER' => 'array',
        ];
        if ($this->option('untuned')) {
            $env += ['DB_JOURNAL_MODE' => 'delete', 'DB_BUSY_TIMEOUT' => '0', 'DB_TRANSACTION_MODE' => 'DEFERRED'];
        }

        try {
            $this->line("Throwaway database: {$database}");
            $this->artisan($env, ['migrate', '--force'])->throw();
            /** @var array{channel: int, users: list<int>} $scene */
            $scene = json_decode(trim($this->artisan($env, ['app:load-test', '--role=prepare', '--users='.$users])->throw()->output()), true);

            $errors = [];
            $started = microtime(true);
            $senders = [];
            foreach ($scene['users'] as $i => $userId) {
                $senders[] = $this->start($env, [
                    'app:load-test', '--role=send', '--user='.$userId, '--channel='.$scene['channel'],
                    '--recipient='.$scene['users'][($i + 1) % count($scene['users'])], '--messages='.$messages,
                ]);
            }

            // One worker at a time, as in production. --stop-when-empty
            // ends it whenever it catches up, so start it again while
            // anyone is still sending.
            $worker = null;
            $workerOutput = '';
            $sending = fn (): bool => collect($senders)->contains(fn (InvokedProcess $sender): bool => $sender->running());
            do {
                if ($worker === null || ! $worker->running()) {
                    if ($worker !== null) {
                        $workerOutput .= $worker->wait()->output().$worker->wait()->errorOutput();
                    }
                    $worker = $this->start($env, ['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1']);
                }
                usleep(100_000);
            } while ($sending());
            $sendTime = microtime(true) - $started;
            $done = $worker->wait();
            $workerOutput .= $done->output().$done->errorOutput();
            // Whatever was queued after it last caught up.
            $last = $this->artisan($env, ['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1']);
            $workerOutput .= $last->output().$last->errorOutput();

            if (str_contains($workerOutput, 'database is locked')) {
                $errors[] = 'The queue worker hit "database is locked".';
            }

            $latencies = [];
            foreach ($senders as $sender) {
                $result = $sender->wait();
                $report = json_decode(trim($result->output()), true);
                if (! is_array($report)) {
                    $errors[] = trim($result->errorOutput()) ?: 'A sender stopped without a report.';

                    continue;
                }
                array_push($latencies, ...$report['latencies']);
                array_push($errors, ...$report['errors']);
            }
            sort($latencies);

            $count = fn (string $table): int => (int) $this->scalar($database, "select count(*) from {$table}");
            $expected = $users * $messages;
            $stored = $count('messages');
            $jobsLeft = $count('jobs');
            $failedJobs = $count('failed_jobs');
            $locked = count(array_filter($errors, fn (string $error): bool => str_contains($error, 'database is locked') || str_contains($error, 'busy')));
            $at = fn (float $share): float => $latencies[min(count($latencies) - 1, (int) floor(count($latencies) * $share))];

            $this->table(['', ''], [
                ['Senders + queue worker', "{$users} + 1"],
                ['Messages stored', "{$stored} of {$expected}"],
                ['Time', sprintf('%.1f s (%.0f messages/s)', $sendTime, $stored / max($sendTime, 0.001))],
                ['Send p50 / p95 / max', $latencies === [] ? '-' : sprintf('%.0f / %.0f / %.0f ms', $at(0.5), $at(0.95), end($latencies))],
                ['"database is locked"', $locked],
                ['Other errors', count($errors) - $locked],
                ['Jobs left / failed', "{$jobsLeft} / {$failedJobs}"],
            ]);

            foreach (array_slice(array_values(array_unique($errors)), 0, 5) as $error) {
                $this->error($error);
            }

            $passed = $errors === [] && $stored === $expected && $jobsLeft === 0 && $failedJobs === 0;
            $passed ? $this->info('No lock errors.') : $this->error('The load test failed.');

            return $passed ? self::SUCCESS : self::FAILURE;
        } finally {
            DB::purge('load_test');
            if (! $this->option('keep')) {
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @unlink($database.$suffix);
                }
            }
        }
    }

    /** In the throwaway database: one server with --users members. */
    private function prepare(): int
    {
        $users = collect(range(1, max(2, (int) $this->option('users'))))->map(fn (int $i): User => User::query()->forceCreate([
            'name' => "Load tester {$i}",
            'username' => "loadtester{$i}",
            'email' => "loadtester{$i}@example.test",
            'password' => Str::random(32),
            'email_verified_at' => now(),
        ]));
        $server = app(CreateServer::class)->create($users->first(), 'Load test');
        foreach ($users->skip(1) as $user) {
            ServerMember::query()->create(['server_id' => $server->id, 'user_id' => $user->id, 'joined_at' => now()]);
        }

        $this->line((string) json_encode([
            'channel' => (int) $server->channels()->value('id'),
            'users' => $users->map(fn (User $user): int => (int) $user->getKey())->all(),
        ]));

        return self::SUCCESS;
    }

    /** One person chatting: send, read the history, mark it read. */
    private function send(): int
    {
        $user = User::query()->findOrFail((int) $this->option('user'));
        $channel = Channel::query()->findOrFail((int) $this->option('channel'));
        $recipient = User::query()->findOrFail((int) $this->option('recipient'));
        $latencies = [];
        $errors = [];

        foreach (range(1, max(1, (int) $this->option('messages'))) as $i) {
            try {
                $started = microtime(true);
                $content = $i % 5 === 0 ? "message {$i}, hi @{$recipient->username}" : "message {$i}";
                app(SendMessage::class)->send($user, $channel, $content);
                $latencies[] = (microtime(true) - $started) * 1000;

                $channel->messages()->with('author')->latest('id')->limit(50)->get();
                app(MarkChannelRead::class)->markRead($user, $channel);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $this->line((string) json_encode(['latencies' => $latencies, 'errors' => $errors]));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $arguments
     */
    private function artisan(array $env, array $arguments): ProcessResult
    {
        return Process::env($env)->path(base_path())->timeout(600)->run([PHP_BINARY, 'artisan', ...$arguments]);
    }

    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $arguments
     */
    private function start(array $env, array $arguments): InvokedProcess
    {
        return Process::env($env)->path(base_path())->timeout(600)->start([PHP_BINARY, 'artisan', ...$arguments]);
    }

    /** A value from the throwaway database, on a connection of its own. */
    private function scalar(string $database, string $sql): mixed
    {
        config(['database.connections.load_test' => [...config('database.connections.sqlite'), 'database' => $database]]);

        return DB::connection('load_test')->scalar($sql);
    }
}
