<?php

declare(strict_types=1);

namespace TransformerX\App;

use TransformerX\App\Http\Response;

/** The single HTML page; everything else is rendered by public/assets/js. No inline script or style (strict CSP). */
final class Shell
{
    /** Changes whenever any front-end file changes; open tabs compare it to notice an update. */
    public static function version(): string
    {
        static $version = null;
        if ($version === null) {
            $latest = 0;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(Config::root() . '/public/assets', \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $latest = max($latest, $file->getMTime());
            }
            $version = substr(md5((string) $latest), 0, 10);
        }
        return $version;
    }

    public static function response(string $basePath): Response
    {
        $ver = self::version();
        $v = static fn (string $file) => $ver;
        $b = htmlspecialchars($basePath, ENT_QUOTES);
        $html = <<<HTML
        <!doctype html>
        <html lang="en" data-theme="system">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <meta name="color-scheme" content="light dark">
        <title>Transformer X</title>
        <link rel="icon" href="$b/assets/img/icon.svg" type="image/svg+xml">
        <link rel="stylesheet" href="$b/assets/css/app.css?v={$v('css/app.css')}">
        <script type="module" src="$b/assets/js/app.js?v={$v('js/app.js')}"></script>
        </head>
        <body>
        <div id="app" data-base="$b" data-version="$ver" aria-live="polite"><div class="boot">Loading Transformer X…</div></div>
        <noscript><p class="noscript">Transformer X needs JavaScript. Enable it in your browser and reload the page.</p></noscript>
        </body>
        </html>
        HTML;
        return Response::html($html);
    }
}
