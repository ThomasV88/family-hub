<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title inertia>{{ config('app.name', 'Family Hub') }}</title>
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#3b6ea8">
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
