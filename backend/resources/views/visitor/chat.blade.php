@extends('visitor.layout')

@section('head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endsection

@section('content')
    <p class="label">{{ __('visitor.chat_label') }}</p>
    <h1 class="message quiet">{{ __('visitor.chat_title') }}</h1>

    @if ($paused)
        <p class="body" role="status">{{ __('visitor.chat_paused') }}</p>
    @else
        <p class="hint notice">{{ __('visitor.chat_notice') }}</p>

        <div class="chat"
             data-messages-url="/t/{{ $token }}/chat/messages"
             data-report-url="/t/{{ $token }}/chat/messages/:id/report"
             data-poll-ms="5000"
             data-you="{{ __('visitor.chat_you') }}"
             data-owner="{{ __('visitor.chat_owner') }}"
             data-report="{{ __('visitor.chat_report') }}"
             data-reported="{{ __('visitor.chat_reported') }}"
             data-report-confirm="{{ __('visitor.chat_report_confirm') }}"
             data-removed="{{ __('visitor.chat_removed') }}"
             data-sending="{{ __('visitor.chat_sending') }}"
             data-send="{{ __('visitor.chat_send') }}"
             data-error="{{ __('visitor.chat_error') }}"
             data-limited="{{ __('visitor.chat_limited') }}"
             data-expired="{{ __('visitor.chat_expired') }}"
             data-unavailable="{{ __('visitor.chat_unavailable') }}">
            <ol class="thread" aria-live="polite" aria-label="{{ __('visitor.chat_title') }}"></ol>
            <p class="hint empty" @if ($quickMessages) hidden @endif>{{ __('visitor.chat_empty') }}</p>

            {{-- A new conversation starts with one tap; "Other" opens the text box below. --}}
            @if ($quickMessages)
                <section class="quick" aria-labelledby="quick-title">
                    <h2 id="quick-title">{{ __('visitor.quick_title') }}</h2>
                    <p class="hint">{{ __('visitor.quick_hint') }}</p>
                    <ul>
                        @foreach ($quickMessages as $preset)
                            <li><button type="button" class="quick-option" data-preset="{{ $preset }}">{{ __('wasla.quick_messages.'.$preset) }}</button></li>
                        @endforeach
                        <li><button type="button" class="quick-option other" aria-controls="chat-composer" aria-expanded="false">{{ __('visitor.quick_other') }}</button></li>
                    </ul>
                </section>
            @endif

            <form class="composer" id="chat-composer" method="post" action="/t/{{ $token }}/chat/messages" @if ($quickMessages) hidden @endif>
                <label class="field" for="chat-body">{{ __('visitor.chat_field') }}</label>
                <textarea id="chat-body" name="body" rows="3" maxlength="500" required></textarea>
                @if ($consentRequired)
                    <label class="consent">
                        <input type="checkbox" name="consent" value="1">
                        <span>{{ __('visitor.chat_consent') }}</span>
                    </label>
                @endif
                <button type="submit" class="action">{{ __('visitor.chat_send') }}</button>
            </form>
            <p class="error" role="alert" hidden></p>
        </div>
    @endif

    <a class="back" href="{{ \App\Http\Middleware\VisitorPageHeaders::link('/t/'.$token) }}">{{ __('visitor.chat_back') }}</a>
@endsection

@section('scripts')
    @unless ($paused)
        <script src="/js/visitor-chat.js" defer></script>
    @endunless
@endsection
