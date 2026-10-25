@php($isBasket = ($source ?? '') === 'basket')
<form class="inquiry-form" data-inquiry-form data-source="{{ $source ?? 'form' }}"
      @if($isBasket) data-basket-form @endif
      action="{{ route('api.inquiries.store') }}" method="post">
    <p class="form-note">{{ __('site.form.required_note') }}</p>

    {{-- honeypot 防垃圾 --}}
    <div class="hp-field" aria-hidden="true">
        <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    @if(!empty($product))
        <input type="hidden" name="product_ids[]" value="{{ $product->id }}">
    @endif

    <div class="form-grid">
        <div class="form-field">
            <label for="if-name">{{ __('site.form.name') }} *</label>
            <input id="if-name" type="text" name="name" required maxlength="128">
        </div>
        <div class="form-field">
            <label for="if-email">{{ __('site.form.email') }} *</label>
            <input id="if-email" type="email" name="email" required maxlength="191">
        </div>
        <div class="form-field">
            <label for="if-phone">{{ __('site.form.phone') }}</label>
            <input id="if-phone" type="text" name="phone" maxlength="64">
        </div>
        <div class="form-field">
            <label for="if-company">{{ __('site.form.company') }}</label>
            <input id="if-company" type="text" name="company" maxlength="191">
        </div>
        <div class="form-field">
            <label for="if-country">{{ __('site.form.country') }}</label>
            <input id="if-country" type="text" name="country" maxlength="8" placeholder="US / DE / AE…">
        </div>
    </div>

    <div class="form-field">
        <label for="if-message">{{ __('site.form.message') }} *</label>
        <textarea id="if-message" name="message" rows="5" required maxlength="5000"
                  placeholder="@if(!empty($industryName)){{ __('industries.form_intro', ['name' => $industryName]) }}@else{{ __('site.form.message_ph') }}@endif">{{ old('message') }}</textarea>
    </div>

    <button type="submit" class="btn btn-primary btn-lg">{{ __('site.form.submit') }}</button>

    <p class="form-reassure">{{ __('site.form.reassure') }}</p>

    <p class="form-status form-status--success" hidden>{{ __('site.form.success') }}</p>
    <p class="form-status form-status--error" hidden>{{ __('site.form.error') }}</p>
</form>
