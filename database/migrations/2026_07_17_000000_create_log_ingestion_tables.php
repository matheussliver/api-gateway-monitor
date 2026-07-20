<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_sources', function (Blueprint $table): void {
            $table->id();
            $table->char('fingerprint', 64)->unique();
            $table->text('path');
            $table->unsignedBigInteger('last_processed_offset')->default(0);
            $table->unsignedBigInteger('last_processed_line')->default(0);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->timestamps(3);
        });

        Schema::create('gateway_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('log_source_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_offset');
            $table->unsignedBigInteger('source_line');
            $table->uuid('consumer_id')->index();
            $table->string('service_name')->index();
            $table->unsignedInteger('latency_proxy');
            $table->unsignedInteger('latency_gateway');
            $table->unsignedInteger('latency_request');
            $table->dateTime('created_at', 3)->index();
            $table->dateTime('processed_at', 3)->index();

            $table->unique(['log_source_id', 'source_offset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_logs');
        Schema::dropIfExists('log_sources');
    }
};
