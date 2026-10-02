<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Own It</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:wght@400;600&family=Instrument+Serif:ital@1&display=swap">
    <link rel="stylesheet" href="/game.css?v={{ filemtime(public_path('game.css')) }}">
</head>
<body>
    <div class="wrap">
        <header class="top">
            <div class="brand"><b>Own</b> It</div>
            <div class="meta" id="meta"></div>
        </header>
        <main class="stage" id="stage"></main>
    </div>
    <div class="hostbar" id="hostbar" hidden><div class="inner" id="hostbar-inner"></div></div>
    <div class="toast" id="toast" role="status" hidden></div>

    <script>window.OWNIT_MODE = @json($mode); window.OWNIT_AVATARS = @json(config('game.avatars'));</script>
    @if ($mode !== 'player')
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
    @endif
    <script src="/game.js?v={{ filemtime(public_path('game.js')) }}"></script>
</body>
</html>
