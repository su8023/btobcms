<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 旧列 attrs（属性键值对）改名 specs：字段名与采购规范表一致
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('attrs', 'specs');
        });

        // 列顺序：先建被 after() 引用的列，再建引用它的列（MySQL 逐条 ALTER 生效）
        Schema::table('products', function (Blueprint $table) {
            // 采购词（前台搜索 / 热词展示的数据源）
            $table->json('core_keywords')->nullable()->after('moq');
            $table->json('hot_trending_keywords')->nullable()->after('core_keywords');
            $table->string('price_range', 64)->nullable()->after('currency');
            $table->string('certification', 255)->nullable()->after('price_range');
            $table->string('lead_time', 64)->nullable()->after('certification');
            $table->string('aql', 64)->nullable()->after('lead_time');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->json('core_keywords')->nullable()->after('description');
            $table->json('hot_trending_keywords')->nullable()->after('core_keywords');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['core_keywords', 'hot_trending_keywords']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['core_keywords', 'hot_trending_keywords', 'price_range', 'certification', 'lead_time', 'aql']);
            $table->renameColumn('specs', 'attrs');
        });
    }
};
