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
            <p class="hint empty">{{ __('visitor.chat_empty') }}</p>

            <form class="composer" method="post" action="/t/{{ $token }}/chat/messages">
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
