<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            // ASSUMPTION: unique constraint mengandalkan collation default
            // Laravel 11 pada MySQL 8 (utf8mb4_0900_ai_ci), yang bersifat
            // case-insensitive. "RYU" dan "ryu" dianggap sama. Bila artist
            // butuh code case-sensitive, kolom ini perlu collation
            // 'utf8mb4_0900_as_cs' secara eksplisit.
            $table->char('code', 3)->unique();
            $table->string('name', 100);
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artists');
    }
};
