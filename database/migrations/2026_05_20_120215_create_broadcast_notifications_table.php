<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('message');
            $table->enum('target_type', ['all', 'companies', 'candidates'])->default('all');
            $table->boolean('sent_via_email')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->integer('sent_count')->default(0);
            $table->timestamps();

            $table->index('target_type');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_notifications');
    }
};
