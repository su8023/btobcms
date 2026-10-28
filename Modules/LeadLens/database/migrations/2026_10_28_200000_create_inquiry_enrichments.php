<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_enrichments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);       // 0~100
            $table->string('tier', 8);                              // hot / warm / cold
            $table->string('email_domain', 191)->nullable();
            $table->boolean('is_free_email')->default(false);
            $table->string('company_site', 191)->nullable();        // 邮箱域名推测的企业官网
            $table->json('details')->nullable();                    // 各评分项明细
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_enrichments');
    }
};
