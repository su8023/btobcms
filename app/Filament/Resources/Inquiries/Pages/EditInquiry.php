<?php

namespace App\Filament\Resources\Inquiries\Pages;

use App\Filament\Resources\Inquiries\InquiryResource;
use App\Mail\InquiryReply;
use App\Models\Inquiry;
use App\Models\InquiryFollowup;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EditInquiry extends EditRecord
{
    protected static string $resource = InquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),

            // 邮件回复买家：Modal 填写主题/正文，发送成功后自动写入一条 email 跟进记录
            Action::make('reply')
                ->label(__('admin.inquiries.reply'))
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalHeading(__('admin.inquiries.reply_modal_heading'))
                ->modalDescription(__('admin.inquiries.reply_modal_description', [
                    'email' => $this->record->email,
                ]))
                ->schema([
                    TextInput::make('subject')
                        ->label(__('admin.inquiries.reply_subject'))
                        ->required()
                        ->maxLength(255)
                        ->default(fn (): string => __('admin.inquiries.reply_subject_default', [
                            'id' => $this->record->id,
                            'site' => (string) config('site.name'),
                        ])),

                    Textarea::make('message')
                        ->label(__('admin.inquiries.reply_message'))
                        ->required()
                        ->rows(8)
                        ->columnSpanFull(),
                ])
                ->modalSubmitActionLabel(__('admin.inquiries.reply_send'))
                ->action(function (array $data): void {
                    $inquiry = $this->record;

                    try {
                        Mail::to($inquiry->email)->send(new InquiryReply(
                            $inquiry,
                            (string) $data['subject'],
                            (string) $data['message'],
                        ));
                    } catch (Throwable $e) {
                        Log::warning('[inquiry-reply] 邮件回复发送失败：'.$e->getMessage(), [
                            'inquiry_id' => $inquiry->id,
                            'to' => $inquiry->email,
                            'exception' => $e,
                        ]);

                        Notification::make()
                            ->title(__('admin.inquiries.reply_failed'))
                            ->warning()
                            ->send();

                        return;
                    }

                    InquiryFollowup::create([
                        'inquiry_id' => $inquiry->id,
                        'channel' => 'email',
                        'user_id' => auth()->id(),
                        'content' => __('admin.inquiries.reply_followup_prefix', [
                            'subject' => $data['subject'],
                        ])."\n\n".$data['message'],
                    ]);

                    Notification::make()
                        ->title(__('admin.inquiries.reply_sent', ['email' => $inquiry->email]))
                        ->success()
                        ->send();
                }),

            // 同邮箱客户历史：弹窗展示该买家的其他询盘，链接直达各自编辑页
            Action::make('history')
                ->label(__('admin.inquiries.history'))
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->email))
                ->modalHeading(__('admin.inquiries.history_modal_heading', [
                    'email' => $this->record->email,
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('admin.inquiries.history_close'))
                ->modalContent(fn () => view('filament.inquiry-history', [
                    'inquiries' => Inquiry::query()
                        ->where('email', $this->record->email)
                        ->whereKeyNot($this->record->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get(),
                ])),
        ];
    }
}
