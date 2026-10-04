<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Created after "orders" so that leads.order_id can be a real foreign key.
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('closer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('platform_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 30)->default('new');
            $table->timestamp('stage_changed_at');
            $table->date('contacted_on');
            $table->unsignedInteger('estimated_value_cents')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->text('last_message')->nullable();
            $table->date('next_follow_up_on')->nullable()->index();
            $table->string('lost_reason', 30)->nullable();
            $table->text('lost_note')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['stage', 'owner_id']);
            $table->index(['owner_id', 'contacted_on']);
            $table->index('contacted_on');
        });

        Schema::create('lead_service', function (Blueprint $table) {
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();

            $table->primary(['lead_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_service');
        Schema::dropIfExists('leads');
    }
};
