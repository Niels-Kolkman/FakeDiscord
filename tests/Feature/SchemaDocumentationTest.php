<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The committed ERD (docs/database-schema.md) matches the migrated
 * schema, and the migrations stay portable / SQLite-safe.
 */
class SchemaDocumentationTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN_TABLES = [
        'users', 'servers', 'server_members', 'channels', 'messages',
        'message_attachments', 'reactions', 'friendships', 'conversations',
        'conversation_participants', 'roles', 'role_user', 'invites', 'bans',
        'channel_categories', 'channel_permission_overrides', 'audit_log_entries',
        'channel_reads', 'notification_mutes',
    ];

    /** Columns the ERD deliberately leaves out. */
    private const UNDOCUMENTED_COLUMNS = ['remember_token'];

    public function test_the_erd_is_committed_as_a_mermaid_diagram(): void
    {
        $this->assertFileExists(base_path('docs/database-schema.md'));
        $this->assertMatchesRegularExpression('/```mermaid\s+erDiagram/', $this->erd());
    }

    public function test_every_domain_table_is_an_erd_entity_listing_its_columns(): void
    {
        $entities = $this->entities();

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertArrayHasKey($table, $entities, "ERD has no entity for [{$table}].");

            $documented = $entities[$table];
            if (in_array('timestamps', $documented, true)) {
                array_push($documented, 'created_at', 'updated_at');
            }

            foreach (Schema::getColumnListing($table) as $column) {
                if (in_array($column, self::UNDOCUMENTED_COLUMNS, true)) {
                    continue;
                }
                $this->assertContains($column, $documented, "ERD entity [{$table}] is missing column [{$column}].");
            }
        }
    }

    public function test_every_foreign_key_appears_as_an_erd_relationship_with_matching_optionality(): void
    {
        preg_match_all('/^\s*(\w+)\s+(\|\||\|o)--o\{\s+(\w+)\s*:/m', $this->erd(), $matches, PREG_SET_ORDER);
        $relationships = [];
        foreach ($matches as [, $parent, $cardinality, $child]) {
            $relationships["{$parent}->{$child}"][] = $cardinality;
        }

        foreach (self::DOMAIN_TABLES as $table) {
            $nullable = collect(Schema::getColumns($table))->pluck('nullable', 'name');

            foreach (Schema::getForeignKeys($table) as $fk) {
                $column = $fk['columns'][0];
                $key = "{$fk['foreign_table']}->{$table}";
                $expected = $nullable[$column] ? '|o' : '||';

                $this->assertArrayHasKey($key, $relationships, "ERD has no relationship for [{$table}.{$column}].");
                $this->assertContains(
                    $expected,
                    $relationships[$key],
                    "ERD relationship [{$key}] should be {$expected} because [{$table}.{$column}] is ".($nullable[$column] ? 'nullable' : 'required').'.',
                );
            }
        }
    }

    public function test_migrations_avoid_raw_sql_and_enum_columns(): void
    {
        $forbidden = [
            'DB::statement' => '/DB::statement\s*\(/',
            'DB::unprepared' => '/DB::unprepared\s*\(/',
            'DB::raw' => '/DB::raw\s*\(/',
            '->enum()' => '/->enum\s*\(/',
            '->change()' => '/->change\s*\(/',
        ];

        foreach (glob(database_path('migrations/*.php')) as $file) {
            $source = file_get_contents($file);
            foreach ($forbidden as $label => $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $source, basename($file)." uses {$label}, which is not SQLite-portable.");
            }
        }
    }

    public function test_env_example_enables_sqlite_foreign_keys(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^DB_CONNECTION=sqlite$/m', $env);
        $this->assertMatchesRegularExpression('/^DB_FOREIGN_KEYS=true$/m', $env);
    }

    private function erd(): string
    {
        return str_replace("\r\n", "\n", file_get_contents(base_path('docs/database-schema.md')));
    }

    /**
     * @return array<string, list<string>> entity name => attribute names
     */
    private function entities(): array
    {
        preg_match_all('/^ {4}(\w+) \{\n(.*?)^ {4}\}/ms', $this->erd(), $blocks, PREG_SET_ORDER);

        $entities = [];
        foreach ($blocks as [, $name, $body]) {
            preg_match_all('/^\s+\S+\s+(\w+)/m', $body, $attributes);
            $entities[$name] = $attributes[1];
        }

        return $entities;
    }
}
