<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('discord_username', 64)->unique();
            $table->string('name', 120)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('payment_name', 120)->nullable();
            $table->char('country', 2)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->unsignedTinyInteger('nurturing_rating')->nullable();
            $table->text('next_upsell_plan')->nullable();
            $table->date('expected_upsell_on')->nullable()->index();
            $table->text('lost_note')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
