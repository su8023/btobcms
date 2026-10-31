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

            // 线索雷达导航徽章：where tier='hot' count，每次后台加载执行
            // 索引随模块表自持：核心迁移不得依赖模块表（插件可被停用/删除）
            $table->index('tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_enrichments');
    }
};
