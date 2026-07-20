<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_logs', function (Blueprint $table): void {
            $table->string('service_name')
                ->collation('utf8mb4_0900_as_cs')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('gateway_logs', function (Blueprint $table): void {
            $table->string('service_name')
                ->collation('utf8mb4_unicode_ci')
                ->change();
        });
    }
};
