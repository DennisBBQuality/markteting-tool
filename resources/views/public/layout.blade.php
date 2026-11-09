<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Informatie over BBQuality The Pitboard en de koppelingen voor contentplanning en publicatie.">
    <title>@yield('title') · BBQuality The Pitboard</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #30251f; background: #f5f3ef; }
        * { box-sizing: border-box; }
        body { margin: 0; line-height: 1.65; }
        header, footer { background: #2d1b12; color: #fff; }
        .wrap { max-width: 960px; margin: auto; padding: 24px; }
        header .wrap { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; }
        .brand { font-weight: 800; font-size: 20px; text-decoration: none; }
        nav { display: flex; flex-wrap: wrap; gap: 16px; }
        header a, footer a { color: #fff; }
        main { max-width: 900px; margin: 32px auto; padding: 32px; background: #fff; border-radius: 16px; }
        h1 { font-size: clamp(28px, 4vw, 42px); line-height: 1.15; margin-top: 0; }
        h2 { font-size: 22px; margin-top: 32px; }
        a { color: #a82f35; text-underline-offset: 3px; }
        a:focus-visible { outline: 3px solid #d8494f; outline-offset: 4px; }
        .intro { font-size: 20px; }
        .button { display: inline-block; background: #c33b42; color: #fff; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 700; }
        .note { background: #f5f3ef; padding: 16px 20px; border-left: 4px solid #c33b42; }
        li { margin: 8px 0; }
        @media(max-width: 600px) { main { margin: 16px; padding: 24px; } nav { gap: 12px; font-size: 14px; } }
    </style>
</head>
<body>
<header>
    <div class="wrap">
        <a class="brand" href="/over-pitboard">BBQuality The Pitboard</a>
        <nav aria-label="Appinformatie">
            <a href="/over-pitboard">Over Pitboard</a>
            <a href="/privacy">Privacy</a>
            <a href="/gebruiksvoorwaarden">Gebruiksvoorwaarden</a>
            <a href="/">Inloggen</a>
        </nav>
    </div>
</header>
<main id="main">@yield('content')</main>
<footer><div class="wrap">Een interne werkomgeving van <a href="https://www.bbquality.nl/">BBQuality</a>. Vragen? <a href="https://www.bbquality.nl/contact/">Neem contact op</a> en vermeld The Pitboard.</div></footer>
</body>
</html>
