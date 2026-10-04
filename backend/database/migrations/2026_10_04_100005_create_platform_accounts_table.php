<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->text('email_password');
            $table->string('discord_email')->nullable()->unique();
            $table->string('discord_username', 64)->nullable();
            $table->text('discord_password');
            $table->date('discord_created_on')->nullable();
            $table->string('recovery_email')->nullable();
            $table->text('recovery_phone')->nullable();
            $table->text('phone_holder_name')->nullable();
            $table->date('batch_date')->index();
            $table->foreignId('workstation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->string('standing', 20)->default('active')->index();
            $table->timestamp('standing_changed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workstation_id', 'standing']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_accounts');
    }
};
