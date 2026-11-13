@php($themePref = \App\Support\ThemePreference::fromRequest(request()))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-pref="{{ $themePref }}"@if ($themePref !== 'system') data-theme="{{ $themePref }}"@endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title inertia>{{ config('app.name', 'Family Hub') }}</title>
        <link rel="manifest" href="/manifest.webmanifest">
        @if ($themePref === 'system')
        <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
        <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#222220">
        @else
        <meta name="theme-color" content="{{ \App\Support\ThemePreference::themeColor($themePref) }}">
        @endif
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon-180x180.png">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="Family Hub">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/pwa.js'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
