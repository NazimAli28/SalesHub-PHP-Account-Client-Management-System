<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('action', 20);
            $table->string('approvable_type')->nullable();
            $table->unsignedBigInteger('approvable_id')->nullable();
            $table->json('payload');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('pending_key', 100)->nullable()->unique();
            $table->text('reason')->nullable();
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['approvable_type', 'approvable_id', 'status']);
            $table->index(['requested_by_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
