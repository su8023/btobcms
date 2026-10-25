<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            Action::make('exportCsv')
                ->label(__('admin.products.export_csv'))
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn (): string => route('admin.products.export')),

            Action::make('import')
                ->label(__('admin.products.import.label'))
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading(__('admin.products.import.heading'))
                ->modalSubmitActionLabel(__('admin.products.import.label'))
                ->schema([
                    FileUpload::make('file')
                        ->label(__('admin.products.import.file'))
                        ->helperText(__('admin.products.import.file_helper'))
                        ->acceptedFileTypes(['text/csv', 'application/csv', 'text/plain'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $file = $data['file'] ?? null;

                    if (! $file instanceof TemporaryUploadedFile) {
                        Notification::make()
                            ->title(__('admin.products.import.failed'))
                            ->danger()
                            ->send();

                        return;
                    }

                    // 上传的临时文件转存到 storage/app/imports/，保留随机副本供命令读取
                    $directory = storage_path('app/imports');

                    if (! is_dir($directory)) {
                        @mkdir($directory, 0755, true);
                    }

                    $storedPath = $directory.'/products-'.now()->format('YmdHis').'-'.Str::random(8).'.csv';

                    if (! @copy($file->getRealPath(), $storedPath)) {
                        Notification::make()
                            ->title(__('admin.products.import.failed'))
                            ->danger()
                            ->send();

                        return;
                    }

                    [$exitCode, $summary] = $this->runImportCommand($storedPath);

                    $notification = Notification::make()
                        ->title($exitCode === Command::SUCCESS
                            ? __('admin.products.import.done')
                            : __('admin.products.import.failed'))
                        ->body($summary !== '' ? $summary : null)
                        ->persistent();

                    $exitCode === Command::SUCCESS
                        ? $notification->success()->send()
                        : $notification->danger()->send();
                }),
        ];
    }

    /**
     * 运行 import:products 并捕获输出摘要（app/Console/Commands 由
     * Application::configure() 默认的 withCommands() 自动注册）。
     *
     * @return array{0: int, 1: string}
     */
    protected function runImportCommand(string $path): array
    {
        $exitCode = Artisan::call('import:products', ['file' => $path]);

        return [$exitCode, trim(Artisan::output())];
    }
}
