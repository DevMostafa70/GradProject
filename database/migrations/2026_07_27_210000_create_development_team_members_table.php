<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('development_team_members', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar', 120);
            $table->string('name_en', 120);
            $table->string('role_ar', 160);
            $table->string('role_en', 160);
            $table->text('bio_ar');
            $table->text('bio_en');
            $table->text('responsibilities_ar')->nullable();
            $table->text('responsibilities_en')->nullable();
            $table->json('skills')->nullable();
            $table->string('image_path')->nullable();
            $table->string('email')->nullable();
            $table->string('linkedin_url', 500)->nullable();
            $table->string('github_url', 500)->nullable();
            $table->string('portfolio_url', 500)->nullable();
            $table->unsignedInteger('display_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'display_order'], 'development_team_public_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('development_team_members');
    }
};
