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

/**
 * Load API key from config.
 */
function _translatewizard_apiKey(App $kirby): ?string
{
    $key = $kirby->option('kirbydesk.translatewizard.deepl.apiKey');
    return is_string($key) && $key !== '' ? $key : null;
}

Kirby::plugin('kirbydesk/translatewizard', [
    'options' => [
        'deepl.apiKey' => null,
        // Note on wiring:
        // • The view-button `translatewizard` is registered below via
        //   `areas.site.buttons` and will render on any page whose
        //   blueprint has no explicit `buttons:` — provided the project
        //   registers it in Kirby's `panel.viewButtons.page` config.
        //   Plugin options are nested under the plugin prefix by Kirby,
        //   so this cannot ship as a default here; add it to your
        //   `site/config/config.php`:
        //
        //     'panel' => [
        //         'viewButtons' => [
        //             'page' => ['open', 'preview', '-', 'settings',
        //                        'translatewizard', 'languages', 'status'],
        //         ],
        //     ],
        //
        // • Blueprints that DO declare `buttons:` must add
        //   `- translatewizard` themselves — blueprint wins over config.
    ],

    'areas' => [
        'site' => function () {
            return [
                'buttons' => [
                    'translatewizard' => function ($model = null) {
                        $kirby   = App::instance();
                        $default = $kirby->defaultLanguage();
                        $current = $kirby->language();

                        // Hide in the default language — nothing to
                        // translate from oneself.
                        if ($default === null || $current === null) return null;
                        if ($current->code() === $default->code()) return null;
                        if ($model === null) return null;

                        $path = $model->panel()?->path() ?? '';

                        // Restore only makes sense once the language's
                        // content file (e.g. *.en.txt) exists.
                        $hasTranslation = $model->version('latest')->exists($current);

                        // Kirby's k-view-button treats `options` as an
                        // exclusive dropdown trigger (dialog is ignored
                        // once options is set). We use that: the button
                        // opens a small menu with two actions.
                        return [
                            'icon'    => 'ai',
                            'title'   => t('translatewizard.button.text', 'AI'),
                            'options' => [
                                [
                                    'label'  => t('translatewizard.action.translate', 'Translate page with AI'),
                                    'icon'   => 'translatewizard-sparkles',
                                    'dialog' => 'translatewizard/' . $path,
                                ],
                                [
                                    'label'  => t('translatewizard.action.restore', 'Restore'),
                                    'icon'     => 'refresh',
                                    'dialog'   => 'translatewizard/reset/' . $path,
                                    'disabled' => $hasTranslation === false,
                                ],
                            ],
                        ];
                    }
                ],
                'dialogs' => [
                    'translatewizard/reset/(:all)' => [
                        'load' => function (string $path) {
                            $kirby   = App::instance();
                            $default = $kirby->defaultLanguage();
                            return [
                                'component' => 'k-text-dialog',
                                'props' => [
                                    'submitButton' => [
                                        'text'  => t('translatewizard.reset.submit', 'Restore'),
                                        'theme' => 'negative',
                                        'icon'  => 'refresh',
                                    ],
                                    'text'  => tt('translatewizard.reset.confirm', 'Restore this language version to the {lang} original? Content in the current language will be overwritten.', [
                                        'lang' => $default->name(),
                                    ]),
                                ],
                            ];
                        },
                        'submit' => function (string $path) {
                            $kirby = App::instance();
                            $model = Find::parent($path);

                            if ($model->permissions()->can('update') === false) {
                                throw new PermissionException(message: 'Not allowed.');
                            }

                            $source = $kirby->defaultLanguage()->code();
                            $target = $kirby->language()->code();
                            if ($source === $target) {
                                throw new InvalidArgumentException(message: 'Cannot reset the default language onto itself.');
                            }

                            // Copy every content field from the default
                            // language onto the current language.
                            $defaultContent = $model->content($source)->toArray();
                            $model->update($defaultContent, $target);

                            return [
                                'event'   => 'model.update',
                                'message' => t('translatewizard.reset.done', 'Content reset.'),
                            ];
                        }
                    ],
                    'translatewizard/(:all)' => [
                        'load' => function (string $path) {
                            $kirby  = App::instance();
                            $model  = Find::parent($path);
                            $target = $kirby->language();

                            return [
                                'component' => 'k-text-dialog',
                                'props' => [
                                    'submitButton' => t('translatewizard.dialog.submit', 'Translate'),
                                    'text' => tt('translatewizard.dialog.confirm', 'Translate this page into {lang}?', [
                                        'lang' => $target->name(),
                                    ]),
                                ],
                            ];
                        },
                        'submit' => function (string $path) {
                            $kirby = App::instance();
                            $model = Find::parent($path);

                            if ($model->permissions()->can('update') === false) {
                                throw new PermissionException(message: 'Not allowed.');
                            }

                            $apiKey = _translatewizard_apiKey($kirby);
                            if ($apiKey === null) {
                                throw new InvalidArgumentException(message: 'No DeepL API key configured (kirbydesk.translatewizard.deepl.apiKey).');
                            }

                            $body   = $kirby->request()->body()->toArray();
                            $source = $body['from'] ?? $kirby->request()->get('from') ?? $kirby->defaultLanguage()->code();
                            $target = $kirby->language()->code();

                            if ($source === $target) {
                                throw new InvalidArgumentException(message: 'Source and target language must differ.');
                            }

                            $units = (new Translator(new DeepL($apiKey)))
                                ->translatePage($model, $source, $target);

                            return [
                                'event'   => 'model.update',
                                'message' => $units === 0
                                    ? t('translatewizard.result.nothing', 'Nothing to translate.')
                                    : tt('translatewizard.result.done', '{units} field(s) translated.', ['units' => $units]),
                            ];
                        }
                    ]
                ]
            ];
        }
    ],

    'translations' => require_once __DIR__ . '/src/extensions/translations.php',

    'api' => [
        'routes' => [
            [
                'pattern' => '(:all)/translatewizard/translate',
                'method'  => 'POST',
                'action'  => function (string $path) {
                    $kirby = App::instance();
                    $model = Find::parent($path);

                    if ($model->permissions()->can('update') === false) {
                        throw new PermissionException(message: 'Not allowed.');
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

                    $apiKey = _translatewizard_apiKey($kirby);
                    if ($apiKey === null) {
                        throw new InvalidArgumentException(message: 'No DeepL API key configured.');
                    }

                    $units = (new Translator(new DeepL($apiKey)))
                        ->translatePage($model, $source, $target);

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
