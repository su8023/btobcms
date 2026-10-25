<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * 全站设置：站点信息、联系方式、首页 SEO 文案。
 * 值存 Setting 表（全局，site_id 为 null），前台通过 site_setting() 读取并回退到 config。
 */
class SiteSettings extends Page
{
    /** @var array<string, mixed> | null */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 32;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('admin.groups.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.settings.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'site_name' => site_setting('site.name', config('site.name')),
            'contact_email' => site_setting('site.contact.email', config('site.contact.email')),
            'contact_phone' => site_setting('site.contact.phone', config('site.contact.phone')),
            'contact_address' => site_setting('site.contact.address', config('site.contact.address')),
            'social_links' => site_setting('site.social_links', ''),
            'custom_css' => site_setting('site.custom_css', ''),
            ...$this->homeSeoDefaults(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.settings.site_section'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('site_name')->label(__('admin.settings.site_name'))->required(),
                        TextInput::make('contact_email')->label(__('admin.settings.contact_email'))->email(),
                        TextInput::make('contact_phone')->label(__('admin.settings.contact_phone'))->tel(),
                        TextInput::make('contact_address')->label(__('admin.settings.contact_address'))->columnSpanFull(),
                        Textarea::make('social_links')
                            ->label(__('admin.settings.social_links'))
                            ->helperText(__('admin.settings.social_links_hint'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('admin.settings.appearance_section'))
                    ->description(__('admin.settings.custom_css_hint'))
                    ->schema([
                        Textarea::make('custom_css')
                            ->label(__('admin.settings.custom_css'))
                            ->rows(8)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('admin.settings.seo_section'))
                    ->description(__('admin.settings.seo_hint'))
                    ->schema([
                        Tabs::make('home-seo')->tabs(
                            collect(site_locales())->map(fn (string $locale) => Tab::make(strtoupper($locale))
                                ->schema([
                                    TextInput::make("home_title_{$locale}")
                                        ->label(__('admin.settings.home_title').' ('.strtoupper($locale).')')
                                        ->maxLength(120),
                                    Textarea::make("home_description_{$locale}")
                                        ->label(__('admin.settings.home_description').' ('.strtoupper($locale).')')
                                        ->rows(3)
                                        ->maxLength(320),
                                ])->columns(1)
                            )->all(),
                        ),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::put('site.name', $data['site_name']);
        Setting::put('site.contact.email', $data['contact_email']);
        Setting::put('site.contact.phone', $data['contact_phone']);
        Setting::put('site.contact.address', $data['contact_address']);
        Setting::put('site.social_links', $data['social_links'] ?? '');
        Setting::put('site.custom_css', $data['custom_css'] ?? '');

        foreach (site_locales() as $locale) {
            Setting::put("seo.home.title.{$locale}", $data["home_title_{$locale}"] ?? null);
            Setting::put("seo.home.description.{$locale}", $data["home_description_{$locale}"] ?? null);
        }

        Notification::make()
            ->title(__('admin.settings.saved'))
            ->success()
            ->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('admin.settings.save'))
                            ->action('save'),
                    ]),
                ]),
        ]);
    }

    /**
     * @return array<string, ?string>
     */
    private function homeSeoDefaults(): array
    {
        $defaults = [];

        foreach (site_locales() as $locale) {
            $defaults["home_title_{$locale}"] = site_setting("seo.home.title.{$locale}");
            $defaults["home_description_{$locale}"] = site_setting("seo.home.description.{$locale}");
        }

        return $defaults;
    }
}
