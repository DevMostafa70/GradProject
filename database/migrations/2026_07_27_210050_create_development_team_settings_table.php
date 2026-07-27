<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('development_team_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_enabled')->default(true);
            $table->string('eyebrow_ar', 120)->default('صُنّاع التجربة');
            $table->string('eyebrow_en', 120)->default('Meet the builders');
            $table->string('title_ar', 255)->default('الفريق الذي بنى Nervu.AI');
            $table->string('title_en', 255)->default('The team behind Nervu.AI');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();
            $table->timestamps();
        });

        DB::table('development_team_settings')->insert([
            'is_enabled' => true,
            'eyebrow_ar' => 'صُنّاع التجربة',
            'eyebrow_en' => 'Meet the builders',
            'title_ar' => 'الفريق الذي بنى Nervu.AI',
            'title_en' => 'The team behind Nervu.AI',
            'description_ar' => 'فريق متعدد التخصصات يجمع بين تطوير البرمجيات، تجربة المستخدم، والذكاء الاصطناعي لبناء تجربة مقابلات أكثر واقعية ووضوحًا.',
            'description_en' => 'A multidisciplinary team combining software engineering, product design, and AI to build a more realistic and insightful interview experience.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('development_team_settings');
    }
};
