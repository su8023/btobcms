<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 128);
            $table->string('email', 191);
            $table->string('phone', 64)->nullable();
            $table->string('company', 191)->nullable();
            $table->string('country', 8)->nullable();
            $table->text('message');
            $table->string('status', 20)->default('new');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 32)->default('form');
            $table->json('utm')->nullable();
            $table->string('landing_url', 500)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedTinyInteger('spam_score')->default(0);
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('email');
        });

        Schema::create('inquiry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('qty', 64)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('inquiry_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20)->default('email');
            $table->text('content');
            $table->timestamp('next_action_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_followups');
        Schema::dropIfExists('inquiry_items');
        Schema::dropIfExists('inquiries');
    }
};
