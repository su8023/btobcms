<x-filament-panels::page>
    <div class="mx-auto max-w-3xl space-y-6">

        {{-- 第 1 步：主题输入 --}}
        <x-filament::section>
            <x-slot:heading>{{ __('admin.ai_studio.step1') }}</x-slot:heading>
            <div class="space-y-4">
                <div>
                    <label class="plugin-field__label">{{ __('admin.ai_studio.topic') }} *</label>
                    <input type="text" wire:model="topic" maxlength="300"
                        placeholder="{{ __('admin.ai_studio.topic_ph') }}"
                        class="plugin-input" />
                </div>
                <div>
                    <label class="plugin-field__label">{{ __('admin.ai_studio.prompt') }}</label>
                    <textarea wire:model="prompt" rows="3"
                        placeholder="{{ __('admin.ai_studio.prompt_ph') }}"
                        class="plugin-input"></textarea>
                </div>
                <div class="plugin-row">
                    <div class="min-w-40">
                        <label class="plugin-field__label">{{ __('admin.ai_studio.lang') }}</label>
                        <select wire:model="lang"
                            class="plugin-input">
                            @foreach($this->langOptions() as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="plugin-field__label">{{ __('admin.ai_studio.words') }}</label>
                        <input type="number" wire:model="words" min="300" max="4000" placeholder="1200"
                            class="plugin-input" style="width: 7rem" />
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
                        <label class="plugin-field__label">{{ __('admin.ai_studio.plan_title') }}</label>
                        <input type="text" wire:model="planTitle"
                            class="plugin-input" />
                    </div>
                    @if($planAngle)
                        <p class="text-sm text-gray-500">💡 {{ $planAngle }}</p>
                    @endif

                    <div>
                        <label class="plugin-field__label">{{ __('admin.ai_studio.plan_outline') }}</label>
                        <div class="space-y-2">
                            @foreach($planOutline as $i => $section)
                                <div class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                    <input type="text" wire:model="planOutline.{{ $i }}.h2"
                                        class="plugin-input" style="font-weight: 500" />
                                    <textarea wire:model="planOutline.{{ $i }}.points" rows="2"
                                        class="plugin-input"></textarea>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="plugin-field__label">{{ __('admin.ai_studio.plan_keywords') }}</label>
                        <input type="text" wire:model="planKeywords"
                            placeholder="{{ __('admin.ai_studio.keywords_ph') }}"
                            class="plugin-input" />
                    </div>

                    <div>
                        <label class="plugin-field__label">{{ __('admin.ai_studio.plan_faq') }}</label>
                        <textarea wire:model="planFaq" rows="3"
                            placeholder="{{ __('admin.ai_studio.faq_ph') }}"
                            class="plugin-input"></textarea>
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
                        <x-filament::button tag="a" href="{{ $this->previewUrl }}" color="gray" target="_blank" rel="noopener">
                            {{ __('admin.ai_studio.preview_draft') }}
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endif

    </div>
</x-filament-panels::page>
