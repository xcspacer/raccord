@if (app(\App\Ai\AnthropicClient::class)->isConfigured())
    <p class="ai-notice">{{ __('profile.privacy.ai_notice') }} <button type="button" class="ai-notice__link" wire:click="$dispatch('open-profile')">{{ __('profile.privacy.ai_notice_link') }}</button></p>
@endif
