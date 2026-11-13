@extends('layouts.plain')

@section('title', __('pages.home_title'))

@section('content')
    <h1 class="message">{{ __('pages.home_title') }}</h1>
    <p class="body">{{ __('pages.home_body') }}</p>
    <p class="body">{{ __('pages.home_hint') }}</p>
@endsection
