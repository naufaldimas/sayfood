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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('customers')->cascadeOnUpdate()->cascadeOnDelete();

            $table->foreignId('event_category_id')->constrained('event_categories')->cascadeOnUpdate()->cascadeOnDelete();

            $table->string('name');
            $table->string('description');
            $table->string('image_url')->nullable();
            $table->date('date');
            $table->string('location');
            $table->enum('status', ['Pending', 'Coming Soon', 'Completed', 'Canceled']);

            // Tambahan kolom
            $table->integer('estimated_participants')->nullable();
            $table->string('supporting_files')->nullable(); // Bisa file path
            $table->integer('duration')->nullable(); // Misalnya '2'
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('group_link')->nullable(); // Link grup
            $table->string('organizer_name')->nullable();
            $table->string('organizer_phone')->nullable();
            $table->string('organizer_email')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
