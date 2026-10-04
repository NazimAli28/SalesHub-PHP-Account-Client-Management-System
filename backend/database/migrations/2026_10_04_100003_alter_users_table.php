<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 120)->change();
        });

        // "username" is nullable at the database level: SQLite cannot add a NOT NULL column
        // without a default to an existing table. The application always sets it.
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
            $table->foreignId('team_id')->nullable()->after('password')->constrained()->nullOnDelete();
            $table->foreignId('workstation_id')->nullable()->after('team_id')->constrained()->nullOnDelete();
            $table->string('avatar_path')->nullable()->after('workstation_id');
            $table->boolean('is_active')->default(true)->index()->after('avatar_path');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
            $table->dropConstrainedForeignId('workstation_id');
            $table->dropSoftDeletes();
            $table->dropColumn(['username', 'avatar_path', 'is_active', 'last_login_at', 'last_login_ip']);
        });
    }
};
