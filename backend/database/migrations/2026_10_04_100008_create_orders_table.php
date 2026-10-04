<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 20)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('closer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('platform_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('type', 10)->default('fresh');
            $table->string('status', 20)->default('pending_payment');
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->date('ordered_on');
            $table->timestamp('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'ordered_on']);
            $table->index(['team_id', 'ordered_on']);
            $table->index(['status', 'ordered_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
