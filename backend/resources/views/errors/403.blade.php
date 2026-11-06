{{-- A signed link that expired or was altered (email verification) says so; any other 403 stays generic. --}}
@php($expired = $exception instanceof \Illuminate\Routing\Exceptions\InvalidSignatureException)
@include('errors.layout', [
    'title' => __($expired ? 'pages.link_expired_title' : 'pages.403_title'),
    'body' => __($expired ? 'pages.link_expired_body' : 'pages.403_body'),
])
