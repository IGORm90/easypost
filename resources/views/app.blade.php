<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Where the app lives. The landing page is served from another host and
             has to ask THIS one who is signed in (resources/js/utils/appOrigin.ts). --}}
        <meta name="app-url" content="{{ config('app.url') }}">

        <title>{{ config('app.name', 'EasyPost') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body class="bg-bg text-text">
        <div id="app"></div>
    </body>
</html>
