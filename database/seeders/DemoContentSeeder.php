<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 安装向导按需勾选调用：只导演示内容（分类/产品/属性模板），
 * 不创建管理员——管理员由安装表单创建，避免生产出现弱密码默认账号。
 */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ContentSeeder::class);
    }
}
