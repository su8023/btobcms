@php
    use App\Filament\Resources\Inquiries\InquiryResource;
    use Illuminate\Support\Str;

    $statusColors = [
        'new' => 'info',
        'read' => 'gray',
        'following' => 'warning',
        'quoted' => 'primary',
        'won' => 'success',
        'lost' => 'danger',
        'spam' => 'danger',
    ];
@endphp

@if ($inquiries->isEmpty())
    <p>{{ __('admin.inquiries.history_empty') }}</p>
@else
    <ul style="list-style:none;margin:0;padding:0;">
        @foreach ($inquiries as $item)
            @php
                $statusOptions = __('admin.inquiries.status_options');
            @endphp
            <li style="padding:12px 0;border-bottom:1px solid rgba(0,0,0,.1);">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <x-filament::link
                        :href="InquiryResource::getUrl('edit', ['record' => $item])"
                        weight="bold"
                    >
                        #{{ $item->id }}
                    </x-filament::link>

                    <x-filament::badge :color="$statusColors[$item->status] ?? 'gray'">
                        {{ $statusOptions[$item->status] ?? $item->status }}
                    </x-filament::badge>

                    <span style="color:#6b7280;font-size:13px;">
                        {{ $item->created_at?->format('Y-m-d H:i') }}
                    </span>
                </div>

                <p style="margin:8px 0 0;color:#374151;">
                    {{ Str::limit((string) $item->message, 100) }}
                </p>
            </li>
        @endforeach
    </ul>
@endif
