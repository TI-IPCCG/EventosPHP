<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    {{-- ?v=filemtime: sem isto o navegador serve o CSS velho do cache depois
     de uma alteração, e a tela "não muda" sem motivo aparente. --}}
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ filemtime(public_path('assets/app.css')) }}">
    @livewireStyles
</head>
<body>
    {{ $slot }}
    <x-toasts />

    @livewireScripts
</body>
</html>
