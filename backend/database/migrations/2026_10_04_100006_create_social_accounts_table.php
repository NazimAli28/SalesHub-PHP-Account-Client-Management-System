<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_account_id')->constrained()->restrictOnDelete();
            $table->string('platform', 20);
            $table->string('username', 100);
            $table->string('login_email')->nullable();
            $table->text('password');
            $table->date('created_on')->nullable();
            $table->boolean('is_in_use')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['platform', 'username']);
            $table->index(['platform_account_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
