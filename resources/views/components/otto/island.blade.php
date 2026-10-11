@php
    $ottoConfig = app(\App\Services\Otto\OttoConversation::class)->frontendConfig(auth()->user());
@endphp
<div id="otto-root"></div>
<script>
    window.__OTTO_CONFIG__ = @json($ottoConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
</script>
