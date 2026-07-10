<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const ROLES = ['owner', 'admin', 'editor', 'reviewer', 'viewer'];

    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('plan', 30)->default('free');
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', self::ROLES);
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'user_id']);
            $table->index(['user_id', 'workspace_id']);
        });

        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->enum('role', self::ROLES);
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->constrained('users');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');
    }
};
