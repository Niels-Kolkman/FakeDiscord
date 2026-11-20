<?php

namespace Tests\Feature\Performance;

use App\Database\ImmediateTransactionPdo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * ISSUE-42: the web process, the queue worker and Reverb share one SQLite
 * file. WAL, a busy timeout and IMMEDIATE transactions keep them from
 * failing with "database is locked".
 *
 * Runs against a temp file: an in-memory database has no WAL and no
 * second connection.
 */
class SqliteTuningTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fakediscord-tuning-'.uniqid('', true).'.sqlite';
        touch($this->databasePath);

        config()->set('database.connections.sqlite.database', $this->databasePath);
        // Like a second process on the same file. It gives up at once
        // instead of waiting, so a held lock shows up as an error.
        config()->set('database.connections.other', [
            ...config('database.connections.sqlite'),
            'busy_timeout' => 0,
        ]);
        DB::purge('sqlite');

        DB::statement('create table notes (id integer primary key, body text)');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        DB::purge('other');

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->databasePath.$suffix);
        }

        parent::tearDown();
    }

    public function test_a_file_database_uses_wal_a_busy_timeout_and_normal_sync(): void
    {
        $this->assertSame('wal', DB::scalar('pragma journal_mode'));
        $this->assertSame(5000, (int) DB::scalar('pragma busy_timeout'));
        // 1 = NORMAL: no fsync per commit, still safe in WAL mode.
        $this->assertSame(1, (int) DB::scalar('pragma synchronous'));
        $this->assertSame(1, (int) DB::scalar('pragma foreign_keys'));
        $this->assertSame('IMMEDIATE', config('database.connections.sqlite.transaction_mode'));
    }

    public function test_a_transaction_takes_the_write_lock_when_it_begins(): void
    {
        // A deferred transaction would take it at its first write, after
        // reading; in WAL mode that upgrade fails at once when someone else
        // wrote in between, whatever busy_timeout says.
        DB::beginTransaction();

        try {
            DB::connection('other')->insert('insert into notes (body) values (?)', ['other process']);
            $this->fail('The other connection could write while a transaction was open.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('database is locked', $e->getMessage());
        } finally {
            DB::rollBack();
        }

        DB::connection('other')->insert('insert into notes (body) values (?)', ['after the rollback']);
        $this->assertSame(1, DB::table('notes')->count());
    }

    public function test_readers_are_not_blocked_by_a_writer(): void
    {
        DB::table('notes')->insert(['body' => 'committed']);

        DB::transaction(function (): void {
            DB::table('notes')->insert(['body' => 'not committed yet']);

            // WAL: the other process still reads, and sees the last commit.
            $this->assertSame(1, DB::connection('other')->table('notes')->count());
        });

        $this->assertSame(2, DB::connection('other')->table('notes')->count());
    }

    public function test_a_writer_waits_for_the_lock_instead_of_failing(): void
    {
        $other = DB::connection('other');
        $other->statement('pragma busy_timeout = 3000');

        // Another PHP process holds the write lock for half a second.
        $script = sprintf(
            '$pdo = new PDO(%s); $pdo->exec("BEGIN IMMEDIATE"); $pdo->exec("insert into notes (body) values (\'child\')"); echo "locked\n"; usleep(500000); $pdo->exec("COMMIT");',
            var_export('sqlite:'.$this->databasePath, true),
        );
        $child = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes);
        if (! is_resource($child)) {
            throw new RuntimeException('Could not start the child process.');
        }
        $this->assertSame("locked\n", fgets($pipes[1]));

        $started = microtime(true);
        $other->insert('insert into notes (body) values (?)', ['waited']);
        $waited = microtime(true) - $started;

        fclose($pipes[1]);
        proc_close($child);

        $this->assertGreaterThan(0.2, $waited, 'The insert should have waited for the lock.');
        $this->assertSame(['child', 'waited'], DB::table('notes')->orderBy('id')->pluck('body')->all());
    }

    public function test_nested_transactions_roll_back_to_their_savepoint(): void
    {
        DB::transaction(function (): void {
            DB::table('notes')->insert(['body' => 'outer']);

            try {
                DB::transaction(function (): void {
                    DB::table('notes')->insert(['body' => 'inner']);

                    throw new RuntimeException('inner fails');
                });
            } catch (RuntimeException) {
                // The outer transaction carries on.
            }
        });

        $this->assertSame(['outer'], DB::table('notes')->pluck('body')->all());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_php_before_8_4_gets_immediate_transactions_through_its_own_pdo(): void
    {
        $pdo = DB::connection()->getPdo();

        if (version_compare(PHP_VERSION, '8.4.0', '>=')) {
            // Laravel sends BEGIN IMMEDIATE itself (transaction_mode).
            $this->assertNotInstanceOf(ImmediateTransactionPdo::class, $pdo);

            return;
        }

        $this->assertInstanceOf(ImmediateTransactionPdo::class, $pdo);
        $this->assertSame('BEGIN IMMEDIATE', $pdo->beginStatement);
        $this->assertFalse($pdo->inTransaction());

        DB::beginTransaction();
        $this->assertTrue($pdo->inTransaction());
        DB::commit();
        $this->assertFalse($pdo->inTransaction());
    }

    public function test_deferred_mode_keeps_the_plain_pdo(): void
    {
        config()->set('database.connections.deferred', [
            ...config('database.connections.sqlite'),
            'transaction_mode' => 'DEFERRED',
        ]);

        $this->assertNotInstanceOf(ImmediateTransactionPdo::class, DB::connection('deferred')->getPdo());
        DB::purge('deferred');
    }

    public function test_migrate_fresh_empties_a_wal_database(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        DB::purge('sqlite');
        $this->assertSame('wal', DB::scalar('pragma journal_mode'));

        $user = DB::table('users')->insertGetId(['name' => 'A', 'username' => 'a', 'email' => 'a@example.test', 'password' => 'x']);
        DB::table('servers')->insert(['owner_id' => $user, 'name' => 'S']);
        // Still in the -wal file, not yet in the database file.
        $this->assertFileExists($this->databasePath.'-wal');

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('servers')->count());
    }
}
