<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Support\TranslatableFields;
use App\Models\Category;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    /**
     * 分类下拉选项：按物化路径深度缩进展示分类树。
     * 表单与列表页批量“移动分类”操作共用，保证两处展示一致。
     *
     * @return array<int, string>
     */
    public static function categoryOptions(): array
    {
        return Category::query()
            ->orderBy('path')
            ->get()
            ->mapWithKeys(fn (Category $c) => [
                $c->id => str_repeat('— ', substr_count((string) $c->path, '/'))
                    .($c->getTranslation('name', default_locale()) ?: $c->slug),
            ])
            ->all();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.common.sections.basics'))->columns(2)->schema([
                    Select::make('category_id')
                        ->label(__('admin.products.fields.category'))
                        ->options(fn (): array => static::categoryOptions())
                        ->searchable()
                        ->preload(),

                    TextInput::make('sku')
                        ->label(__('admin.products.fields.sku'))
                        ->maxLength(64),

                    TextInput::make('slug')
                        ->label(__('admin.common.slug'))
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Select::make('status')
                        ->label(__('admin.common.status'))
                        ->options(__('admin.products.status_options'))
                        ->default('published')
                        ->required(),

                    TextInput::make('price_min')->label(__('admin.products.fields.price_min'))->numeric()->minValue(0),
                    TextInput::make('price_max')->label(__('admin.products.fields.price_max'))->numeric()->minValue(0),
                    TextInput::make('currency')->label(__('admin.common.currency'))->default('USD')->maxLength(8),
                    TextInput::make('moq')->label(__('admin.products.fields.moq'))->maxLength(64),
                    TextInput::make('unit')->label(__('admin.products.fields.unit'))->maxLength(32),
                    TextInput::make('sort_order')->label(__('admin.common.sort_order'))->numeric()->default(0),
                ]),

                Section::make(__('admin.common.sections.content'))->schema([
                    TranslatableFields::tabs('name', __('admin.products.name'), 'text', requireDefault: true),
                    TranslatableFields::tabs('summary', __('admin.products.summary'), 'textarea'),
                    TranslatableFields::tabs('description', __('admin.products.description'), 'rich'),
                ]),

                Section::make(__('admin.products.sections.attributes'))
                    ->description(__('admin.products.attrs.description'))
                    ->schema([
                        KeyValue::make('attrs')
                            ->keyLabel(__('admin.products.attrs.key'))
                            ->valueLabel(__('admin.common.value')),
                    ]),

                Section::make(__('admin.products.sections.images'))->schema([
                    FileUpload::make('uploaded_images')
                        ->label(__('admin.products.fields.images'))
                        ->multiple()
                        ->reorderable()
                        ->disk('public')
                        ->directory('products')
                        ->image()
                        ->imageEditor()
                        ->maxFiles(12),
                ]),

                Section::make(__('admin.products.sections.alt'))
                    ->description(__('admin.products.alt.description'))
                    ->schema([
                        // 既有图片的 ALT 编辑：由 SavesSeoAndMedia 在 fill 时组装、save 时写回 Attachment.alt
                        Repeater::make('alt_fields')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(2)
                            ->schema([
                                Hidden::make('image_id'),

                                Placeholder::make('path')
                                    ->label(__('admin.products.alt.image'))
                                    ->columnSpanFull(),

                                ...array_map(
                                    fn (string $locale): TextInput => TextInput::make('alt_'.$locale)
                                        ->label(strtoupper($locale))
                                        ->maxLength(255),
                                    site_locales(),
                                ),
                            ]),
                    ]),

                TranslatableFields::seoSection(),
            ]);
    }
}
