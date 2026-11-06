@extends('visitor.layout')

@section('content')
    <p class="label">{{ __('visitor.note_label') }}</p>
    <h1 class="message">{{ $message }}</h1>

    <a class="action" href="/t/{{ $token }}/chat">{{ __('visitor.message_owner') }}</a>
    <p class="hint">{{ __('visitor.message_hint') }}</p>

    @if ($contacts->isNotEmpty())
        <section class="contacts" aria-labelledby="contacts-title"
                 data-busy="{{ __('visitor.revealing') }}"
                 data-call="{{ __('visitor.call') }}"
                 data-error="{{ __('visitor.reveal_error') }}"
                 data-limited="{{ __('visitor.reveal_limited') }}">
            <h2 id="contacts-title">{{ __('visitor.contacts_title') }}</h2>
            <p class="hint">{{ __('visitor.contacts_hint') }}</p>
            <ul>
                @foreach ($contacts as $contact)
                    <li>
                        <span class="who">{{ $contact->label }}</span>
                        <button type="button" class="reveal" data-url="/t/{{ $token }}/contacts/{{ $contact->id }}/reveal">{{ __('visitor.reveal') }}</button>
                    </li>
                @endforeach
            </ul>
            <p class="error" role="alert" hidden></p>
        </section>
    @endif
@endsection

@section('scripts')
    @if ($contacts->isNotEmpty())
        <script src="/js/visitor.js" defer></script>
    @endif
@endsection
