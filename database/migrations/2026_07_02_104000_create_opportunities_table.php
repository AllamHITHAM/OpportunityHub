<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                  ->constrained('organization_profiles')
                  ->cascadeOnDelete();

            $table->string('title');
            $table->longText('description');

            $table->enum('opportunity_type', [
                'job',
                'internship',
                'volunteer',
                'scholarship',
                'competition',
            ]);

            $table->enum('employment_type', [
                'full_time',
                'part_time',
                'contract',
            ]);

            $table->enum('work_mode', [
                'remote',
                'hybrid',
                'onsite',
            ]);

            $table->enum('experience_level', [
                'no_experience',
                'junior',
                'mid',
                'senior',
                'expert',
            ]);

            $table->enum('education_level', [
                'high_school',
                'diploma',
                'bachelor',
                'master',
                'phd',
            ])->nullable();

            $table->string('field_of_study')->nullable();
            $table->string('location')->nullable();
            $table->decimal('salary_min', 10, 2)->nullable();
            $table->decimal('salary_max', 10, 2)->nullable();
            $table->date('application_deadline')->nullable();
            $table->unsignedInteger('positions_available')->default(1);

            $table->enum('status', ['draft', 'open', 'closed'])->default('open');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
