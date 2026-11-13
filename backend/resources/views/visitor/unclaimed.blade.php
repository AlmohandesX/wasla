@extends('visitor.layout')

@section('content')
    <h1 class="message quiet">{{ __('visitor.unclaimed_title') }}</h1>
    <p class="body">{{ __('visitor.unclaimed_body') }}</p>
    <ol class="steps">
        <li>{{ __('visitor.unclaimed_step_1') }}</li>
        <li>{{ __('visitor.unclaimed_step_2') }}</li>
        <li>{{ __('visitor.unclaimed_step_3') }}</li>
    </ol>
@endsection
