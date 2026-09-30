<?php

namespace Tests\Feature;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\MysqlCliDumper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Bagian yang bisa diuji dari pembungkus mysqldump/mysql TANPA mysql: nama
 * biner dapat disuntikkan, jadi test memakai skrip shell kecil sebagai biner
 * pengganti dan memeriksa perilaku proses SUNGGUHAN — argumen (dan escaping-nya),
 * jalur kata sandi, PATH, kode keluar, stderr, dan keluaran kosong.
 * (Kata sandi di bawah hanya data uji.)
 */
class MysqlCliDumperTest extends TestCase
{
    private string $dir;

    private const PASSWORD = 'p@ss w0rd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/boothpos-dumper-test-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function script(string $name, string $body): string
    {
        $path = "{$this->dir}/{$name}";
        file_put_contents($path, "#!/bin/sh\n{$body}\n");
        chmod($path, 0755);

        return $path;
    }

    private function dumper(string $dumpBin = 'mysqldump', string $restoreBin = 'mysql'): MysqlCliDumper
    {
        return new MysqlCliDumper(
            ['host' => 'db.internal', 'username' => "app'user", 'password' => self::PASSWORD, 'database' => 'booth pos'],
            $dumpBin,
            $restoreBin,
        );
    }

    public function test_dump_passes_escaped_args_and_the_password_only_through_the_environment(): void
    {
        // Menulis argumen yang diterima, MYSQL_PWD, dan status PATH ke stdout (= berkas dump).
        $bin = $this->script('fake-mysqldump', 'echo "ARGS: $*"; echo "PWD: $MYSQL_PWD"; [ -n "$PATH" ] && echo "PATH_OK"');
        $target = "{$this->dir}/out dir's.sql";

        $this->dumper($bin)->dump($target);

        $out = file_get_contents($target);
        $this->assertStringContainsString('--host=db.internal', $out);
        $this->assertStringContainsString("--user=app'user", $out);
        $this->assertStringContainsString('booth pos', $out);
        $this->assertStringContainsString('PWD: '.self::PASSWORD, $out);   // sampai ke proses anak lewat env
        $this->assertStringNotContainsString('ARGS: '.self::PASSWORD, $out);
        $this->assertStringNotContainsString(self::PASSWORD, explode('PWD:', $out)[0]); // TIDAK ada di argumen
        $this->assertStringContainsString('PATH_OK', $out);                 // environment digabung, bukan diganti
    }

    public function test_dump_failure_surfaces_the_tools_error_message(): void
    {
        $bin = $this->script('failing-mysqldump', 'echo "Access denied for user" >&2; exit 3');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Access denied for user');

        $this->dumper($bin)->dump("{$this->dir}/db.sql");
    }

    public function test_an_empty_dump_is_an_error_even_when_the_tool_exits_zero(): void
    {
        $bin = $this->script('silent-mysqldump', 'exit 0');

        $this->expectException(BackupException::class);

        $this->dumper($bin)->dump("{$this->dir}/db.sql");
    }

    public function test_restore_feeds_the_file_on_stdin_with_escaped_args_and_env_password(): void
    {
        $captured = "{$this->dir}/captured.txt";
        $bin = $this->script('fake-mysql', "{ echo \"ARGS: \$*\"; echo \"PWD: \$MYSQL_PWD\"; cat; } > '{$captured}'");
        $sqlFile = "{$this->dir}/restore me.sql";
        file_put_contents($sqlFile, "CREATE TABLE `settings` (id int);\n");

        $this->dumper('mysqldump', $bin)->restore($sqlFile);

        $out = file_get_contents($captured);
        $this->assertStringContainsString('--host=db.internal', $out);
        $this->assertStringContainsString('PWD: '.self::PASSWORD, $out);
        $this->assertStringContainsString('CREATE TABLE `settings`', $out); // isi berkas sampai lewat stdin
        $this->assertStringNotContainsString('ARGS: '.self::PASSWORD, $out);
    }

    public function test_restore_failure_surfaces_the_tools_error_message(): void
    {
        $bin = $this->script('failing-mysql', 'echo "ERROR 1045: bad credentials" >&2; exit 1');
        $sqlFile = "{$this->dir}/in.sql";
        file_put_contents($sqlFile, 'x');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('bad credentials');

        $this->dumper('mysqldump', $bin)->restore($sqlFile);
    }

    public function test_restoring_a_missing_file_fails_before_running_anything(): void
    {
        $marker = "{$this->dir}/ran.txt";
        $bin = $this->script('should-not-run', "touch '{$marker}'");

        try {
            $this->dumper('mysqldump', $bin)->restore("{$this->dir}/nope.sql");
            $this->fail('expected BackupException');
        } catch (BackupException) {
            $this->assertFileDoesNotExist($marker);
        }
    }

    public function test_the_container_resolves_the_real_dumper_by_default(): void
    {
        $this->assertInstanceOf(MysqlCliDumper::class, app(DatabaseDumper::class));
    }
}
