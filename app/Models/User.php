<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'locale', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** 角色：管理员（全量权限） */
    public const ROLE_ADMIN = 'admin';

    /** 角色：内容编辑（产品/分类/文章/询盘） */
    public const ROLE_EDITOR = 'editor';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * 是否管理员：可进入插件中心 / 主题中心 / 更新中心 / 站点设置 / 属性 code 等高危面板。
     *
     * role 为 null 表示「迁移尚未执行」（代码先上线、migrate 后跑）：
     * 此时一律按管理员放行，避免升级窗口里所有人都被挡在后台外。
     */
    public function isAdmin(): bool
    {
        return $this->role === null || $this->role === self::ROLE_ADMIN;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // 两种角色都能进后台；真正的高危能力由各 Page/Resource 的 canAccess() 按角色收口
        return $this->role === null
            || in_array($this->role, [self::ROLE_ADMIN, self::ROLE_EDITOR], true);
    }
}
