<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneException;
use App\Services\DemoClone\SafeError;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Tests\TestCase;

class SafeErrorTest extends TestCase
{
    // TestCase::setUp() menulis baris aktivasi lisensi (lihat FileCopierTest).
    use RefreshDatabase;

    public function test_a_query_exception_never_exposes_bindings_or_the_driver_message(): void
    {
        $pdo = new PDOException("SQLSTATE[23000]: Duplicate entry 'SENTINEL-PHONE-123' for key 'customers.x'");
        $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'SENTINEL-PHONE-123'"];
        $e = new QueryException('mysql', 'insert into `customers` (`phone`) values (?)', ['SENTINEL-PHONE-123'], $pdo);

        $text = SafeError::describe($e);

        $this->assertStringNotContainsString('SENTINEL-PHONE-123', $text);
        $this->assertStringContainsString('23000', $text);
        $this->assertStringContainsString('customers', $text);
    }

    public function test_a_clone_exception_message_is_passed_through(): void
    {
        $this->assertSame('orders.customer_id (id 4): induk x', SafeError::describe(new CloneException('orders.customer_id (id 4): induk x')));
    }

    public function test_any_other_exception_is_reduced_to_its_class(): void
    {
        $text = SafeError::describe(new \RuntimeException('contains SENTINEL-EMAIL@example.test'));

        $this->assertStringNotContainsString('SENTINEL-EMAIL', $text);
        $this->assertStringContainsString('RuntimeException', $text);
    }
}
