<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Painel admin de backup do banco.
 *
 * O dump real só roda em MySQL/MariaDB; a suíte usa SQLite, então o service é
 * substituído por um mock em todos os testes de HTTP. O teste final valida a
 * guarda de driver do service (mensagem clara em vez de erro de SQL).
 */
class AdminDatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'olho-dump-') ?: sys_get_temp_dir().'/olho-dump';

        file_put_contents($this->file, "-- dump de teste\nSELECT 1;\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.backup.index'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_backup_area(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.backup.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.backup.dump'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.backup.download'))->assertForbidden();
    }

    public function test_admin_sees_backup_page_with_destination_path(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(DatabaseBackup::class, function (MockInterface $mock): void {
            $mock->shouldReceive('path')->once()->andReturn(database_path('backups/init.sql'));
            $mock->shouldReceive('exists')->once()->andReturnFalse();
            $mock->shouldReceive('size')->once()->andReturnNull();
            $mock->shouldReceive('modifiedAt')->once()->andReturnNull();
        });

        $this->actingAs($admin)
            ->get(route('admin.backup.index'))
            ->assertOk()
            ->assertSee('Backup do banco')
            ->assertSee('database/backups/init.sql')
            ->assertSee('Sem dump gerado');
    }

    public function test_admin_can_generate_dump(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(DatabaseBackup::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dump')->once()->andReturn([
                'path' => '/var/www/html/database/backups/init.sql',
                'bytes' => 2048,
                'tables' => 26,
                'rows' => 123,
                'duration_ms' => 42,
            ]);
        });

        $this->actingAs($admin)
            ->post(route('admin.backup.dump'))
            ->assertRedirect(route('admin.backup.index'))
            ->assertSessionHas('status');

        $audit = AuditLog::query()->where('action', 'admin.database_dump')->first();

        $this->assertNotNull($audit);
        $this->assertSame(26, $audit->metadata['tables']);
        $this->assertSame(123, $audit->metadata['rows']);
        $this->assertSame(2048, $audit->metadata['bytes']);
        $this->assertSame(DatabaseBackup::RELATIVE_PATH, $audit->metadata['path']);
    }

    public function test_admin_can_generate_and_download_in_one_action(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(DatabaseBackup::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dump')->once()->andReturn([
                'path' => '/var/www/html/database/backups/init.sql',
                'bytes' => 2048,
                'tables' => 26,
                'rows' => 123,
                'duration_ms' => 42,
            ]);
            $mock->shouldReceive('exists')->once()->andReturnTrue();
            $mock->shouldReceive('size')->once()->andReturn(2048);
            $mock->shouldReceive('path')->once()->andReturn($this->file);
        });

        $this->actingAs($admin)
            ->post(route('admin.backup.dump'), ['download' => '1'])
            ->assertOk()
            ->assertDownload('init.sql');

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.database_dump']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.database_dump_download']);
    }

    public function test_download_without_dump_redirects_with_error(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(DatabaseBackup::class, function (MockInterface $mock): void {
            $mock->shouldReceive('exists')->once()->andReturnFalse();
        });

        $this->actingAs($admin)
            ->get(route('admin.backup.download'))
            ->assertRedirect(route('admin.backup.index'))
            ->assertSessionHas('error');
    }

    public function test_admin_can_download_existing_dump(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(DatabaseBackup::class, function (MockInterface $mock): void {
            $mock->shouldReceive('exists')->once()->andReturnTrue();
            $mock->shouldReceive('size')->once()->andReturn(20);
            $mock->shouldReceive('path')->once()->andReturn($this->file);
        });

        $this->actingAs($admin)
            ->get(route('admin.backup.download'))
            ->assertOk()
            ->assertHeader('content-type', 'application/sql; charset=UTF-8')
            ->assertDownload('init.sql');
    }

    public function test_generation_failure_is_reported_without_leaking_a_stack_trace(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(DatabaseBackup::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dump')->once()->andThrow(
                new RuntimeException('O diretório database/backups/init.sql não é gravável pelo processo web.'),
            );
        });

        $this->actingAs($admin)
            ->post(route('admin.backup.dump'))
            ->assertRedirect(route('admin.backup.index'))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'não é gravável'));
    }

    public function test_service_refuses_connections_that_are_not_mysql_or_mariadb(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('só é suportado para MySQL/MariaDB');

        app(DatabaseBackup::class)->dump();
    }
}
