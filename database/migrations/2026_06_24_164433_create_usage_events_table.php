<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('usage_events')) {
            return;
        }

        Schema::create('usage_events', function (Blueprint $table): void {
                        $table->engine('InnoDB');

            $table->id();

            $table
                ->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('event_type');
            $table->unsignedInteger('quantity')->default(1);

            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();

            $table->string('idempotency_key')->nullable()->unique();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'event_type']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
