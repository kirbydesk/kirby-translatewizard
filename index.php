<?php

use Kirby\Cms\App;
use Kirby\Cms\Find;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\PermissionException;
use Kirbydesk\Translatewizard\DeepL;
use Kirbydesk\Translatewizard\Translator;

@include_once __DIR__ . '/vendor/autoload.php';
// PSR-4 fallback for local (unlinked) install
spl_autoload_register(function (string $class): void {
    $prefix = 'Kirbydesk\\Translatewizard\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

Kirby::plugin('kirbydesk/translatewizard', [
    'options' => [
        // Explicit key wins; otherwise fall back to content-translator's
        // DeepL config so users don't have to configure it twice.
        'deepl.apiKey' => null,
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => '(:all)/translatewizard/translate',
                'method'  => 'POST',
                'action'  => function (string $path) {
                    $kirby = App::instance();
                    $model = Find::parent($path);

                    if ($model->permissions()->can('update') === false) {
                        throw new PermissionException(
                            message: 'You are not allowed to translate this page.'
                        );
                    }

                    $body   = $kirby->request()->body()->toArray();
                    $target = $body['to']   ?? null;
                    $source = $body['from'] ?? $kirby->defaultLanguage()?->code();

                    if (!is_string($target) || $target === '') {
                        throw new InvalidArgumentException(message: 'Missing "to" (target language code).');
                    }
                    if (!is_string($source) || $source === '') {
                        throw new InvalidArgumentException(message: 'Missing "from" (source language code).');
                    }
                    if ($source === $target) {
                        throw new InvalidArgumentException(message: 'Source and target language must differ.');
                    }

                    $apiKey = $kirby->option('kirbydesk.translatewizard.deepl.apiKey');
                    if (!is_string($apiKey) || $apiKey === '') {
                        // fall back to content-translator config
                        $apiKey = $kirby->option('johannschopplich.content-translator.DeepL.apiKey');
                    }
                    if (!is_string($apiKey) || $apiKey === '') {
                        throw new InvalidArgumentException(message: 'No DeepL API key configured.');
                    }

                    $translator = new Translator(new DeepL($apiKey));
                    $units      = $translator->translatePage($model, $source, $target);

                    return [
                        'status' => 'ok',
                        'units'  => $units,
                        'from'   => $source,
                        'to'     => $target,
                    ];
                }
            ]
        ]
    ]
]);
