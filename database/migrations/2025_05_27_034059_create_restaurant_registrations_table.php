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
        Schema::create('restaurant_registrations', function (Blueprint $table) {
            $table->id();
            
            $table->string('name', 255);
            $table->string('address', 255);
            $table->string('email', 320);
            $table->enum('status', ['pending', 'operational', 'rejected'])->default('pending');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_registrations');
    }
};
