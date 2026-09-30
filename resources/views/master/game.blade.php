<!DOCTYPE html>
<html lang="{{ __('game/global.lang_code') }}">
    <head>
        <title>{{ $gameTitle }}</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="shortcut icon" href="favicon.ico">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/base.css') }}">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/default.css') }}">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/redesign.css') }}">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/formate.css') }}">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/upload/skins/xgproyect/formate.css') }}">
        <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/mobile.css') }}?v=6">
        <meta http-equiv="Content-Type" content="text/html;charset=UTF-8">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="generator" content="XG Proyect {{ config('version.files') }}" />
        <script type="text/javascript" src="{{ asset('assets/js/overlib-min.js') }}"></script>
        @yield('metatags')
    </head>
    <body class="{{ isset($noLeftMenu) ? 'popup-page' : '' }}">
        @if (isset($noLeftMenu))
        <!-- Pop-up pages (notes, rename, combat report, phalanx...) have no menu to open: on phones
             they get a Back bar instead of a hamburger that opens an empty drawer -->
        <a class="mobile-back" href="game.php?page=overview" onclick="if (history.length > 1) { history.back(); return false; }">&#8592; Back</a>
        @else
        <!-- Mobile hamburger menu -->
        <div class="hamburger-btn" id="hamburgerBtn" onclick="toggleMobileMenu()">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <div class="menu-overlay" id="menuOverlay" onclick="closeMobileMenu()"></div>
        @endif

        <div id="container">
            <div id="menu">
                @if (!isset($noLeftMenu))
                <x-game.leftmenu />
                @endif
            </div>
            <div id="page-content">
                <div id="navbar">
                    @if (!isset($noTopnav))
                    <x-game.topnav />
                    @endif
                </div>
                <div id="content" role="main">
                    <br>
                    @yield('content')
                </div>
            </div>
        </div>

        <script>
            // Phones: pop-up links (f() in the menu/overview/messages, fenster() in the galaxy) open a
            // new tab with no way back but the phone's back button. Open them in the same tab instead.
            if (window.matchMedia('(max-width: 768px)').matches) {
                window.f = window.fenster = function (targetUrl) {
                    window.location.href = targetUrl;
                };
            }
            function toggleMobileMenu() {
                var menu = document.getElementById('menu');
                var overlay = document.getElementById('menuOverlay');
                var btn = document.getElementById('hamburgerBtn');
                if (!btn) {
                    return;
                }
                menu.classList.toggle('open');
                overlay.classList.toggle('active');
                btn.classList.toggle('active');
            }
            function closeMobileMenu() {
                var menu = document.getElementById('menu');
                var overlay = document.getElementById('menuOverlay');
                var btn = document.getElementById('hamburgerBtn');
                if (!btn) {
                    return;
                }
                menu.classList.remove('open');
                overlay.classList.remove('active');
                btn.classList.remove('active');
            }
            // Close menu when clicking a menu link
            document.querySelectorAll('#menu a').forEach(function(link) {
                link.addEventListener('click', function() {
                    closeMobileMenu();
                });
            });
        </script>
    </body>
</html>