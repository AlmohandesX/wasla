@extends('layouts.plain')

@section('head')
    <meta name="robots" content="noindex, nofollow">
@endsection

@section('title', $title)

@section('content')
    <h1 class="message quiet">{{ $title }}</h1>
    <p class="body">{{ $body }}</p>
    <p class="body"><a href="/">{{ __('pages.back_home') }}</a></p>
@endsection
