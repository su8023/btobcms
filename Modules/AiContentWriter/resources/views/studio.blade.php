<x-filament-panels::page>
    <div class="max-w-3xl mx-auto space-y-6">

        {{-- 第 1 步：主题输入 --}}
        <x-filament::section>
            <x-slot:heading>{{ __('admin.ai_studio.step1') }}</x-slot:heading>
            <div class="space-y-3">
                <div>
                    <label class="text-sm font-medium">{{ __('admin.ai_studio.topic') }} *</label>
                    <x-filament::input wire:model="topic" type="text" maxlength="300"
                        placeholder="{{ __('admin.ai_studio.topic_ph') }}" />
                </div>
                <div>
                    <label class="text-sm font-medium">{{ __('admin.ai_studio.prompt') }}</label>
                    <textarea wire:model="prompt" rows="3" class="fi-input w-full"
                          placeholder="{{ __('admin.ai_studio.prompt_ph') }}"></textarea>
                </div>
                <div class="flex gap-4 items-end">
                    <div>
                        <label class="text-sm font-medium">{{ __('admin.ai_studio.lang') }}</label>
                        <select wire:model="lang" class="fi-input">
                            @foreach($this->langOptions() as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium">{{ __('admin.ai_studio.words') }}</label>
                        <x-filament::input wire:model="words" type="number" min="300" max="4000"
                            placeholder="1200" style="width:110px" />
                    </div>
                    <x-filament::button wire:click="makePlan" wire:loading.attr="disabled" color="primary">
                        <span wire:loading wire:target="makePlan">⏳ {{ __('admin.ai_studio.thinking') }}</span>
                        <span wire:loading.remove wire:target="makePlan">{{ __('admin.ai_studio.make_plan') }}</span>
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        {{-- 第 2 步：方案（可编辑） --}}
        @if($planTitle !== null)
            <x-filament::section>
                <x-slot:heading>{{ __('admin.ai_studio.step2') }}</x-slot:heading>
                <div class="space-y-4">
                    <div>
                        <label class="text-sm font-medium">{{ __('admin.ai_studio.plan_title') }}</label>
                        <x-filament::input wire:model="planTitle" type="text" />
                    </div>
                    @if($planAngle)
                        <p class="text-sm text-gray-500">💡 {{ $planAngle }}</p>
                    @endif

                    <div>
                        <label class="text-sm font-medium">{{ __('admin.ai_studio.plan_outline') }}</label>
                        <div class="space-y-2">
                            @foreach($planOutline as $i => $section)
                                <div class="rounded-lg border p-3 space-y-2">
                                    <x-filament::input wire:model="planOutline.{{ $i }}.h2" type="text" />
                                    <textarea wire:model="planOutline.{{ $i }}.points" rows="2" class="fi-input w-full"></textarea>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-medium">{{ __('admin.ai_studio.plan_keywords') }}</label>
                        <x-filament::input wire:model="planKeywords" type="text"
                            placeholder="{{ __('admin.ai_studio.keywords_ph') }}" />
                    </div>

                    <div>
                        <label class="text-sm font-medium">{{ __('admin.ai_studio.plan_faq') }}</label>
                        <textarea wire:model="planFaq" rows="3" class="fi-input w-full"
                          placeholder="{{ __('admin.ai_studio.faq_ph') }}"></textarea>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-filament::button wire:click="writeArticle" wire:loading.attr="disabled" color="success">
                            <span wire:loading wire:target="writeArticle">⏳ {{ __('admin.ai_studio.writing') }}</span>
                            <span wire:loading.remove wire:target="writeArticle">{{ __('admin.ai_studio.write_now') }}</span>
                        </x-filament::button>
                        <span class="text-xs text-gray-500">{{ __('admin.ai_studio.editable_hint') }}</span>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- 完成态 --}}
        @if($createdPostId !== null)
            <x-filament::section>
                <x-slot:heading>{{ __('admin.ai_studio.done') }}</x-slot:heading>
                <div class="space-y-3">
                    <p class="text-sm text-gray-500">{{ __('admin.ai_studio.done_hint') }}</p>
                    <div class="flex gap-3">
                        <x-filament::button tag="a" href="/admin/posts/{{ $createdPostId }}/edit" color="primary">
                            {{ __('admin.ai_studio.open_editor') }}
                        </x-filament::button>
                        <x-filament::button tag="a" href="/blog/{{ $createdSlug }}" color="gray" target="_blank" rel="noopener">
                            {{ __('admin.ai_studio.preview_draft') }}
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endif

    </div>
</x-filament-panels::page>
