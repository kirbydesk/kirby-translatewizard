<?php

use Kirby\Cms\App;
use Kirby\Cms\Find;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\PermissionException;
use Kirbydesk\Translatewizard\DeepL;
use Kirbydesk\Translatewizard\Fields;
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
 * DeepL key: plugin option (config.php) first, then the project's .env
 * (managed in the Project Wizard), then the process environment.
 */
function _translatewizard_apiKey(App $kirby): ?string
{
    $key = $kirby->option('kirbydesk.translatewizard.deepl.apiKey')
        ?: (class_exists('pwSecrets') ? pwSecrets::get('DEEPL_API_KEY') : '')
        ?: getenv('DEEPL_API_KEY');
    return is_string($key) && $key !== '' ? $key : null;
}

/**
 * The translation actions for a page view – only in a secondary language
 * (the default language cannot translate to itself).
 */
function _translatewizard_actions($model): array
{
    $kirby   = App::instance();
    $default = $kirby->defaultLanguage();
    $current = $kirby->language();

    if ($default === null || $current === null) return [];
    if ($current->code() === $default->code()) return [];

    $path = $model->panel()?->path() ?? '';

    // Restore only makes sense once the language's
    // content file (e.g. *.en.txt) exists.
    $hasTranslation = $model->version('latest')->exists($current);

    return [
        [
            'label'  => t('translatewizard.action.translate', 'Translate page with AI'),
            'icon'   => 'translatewizard-sparkles',
            'dialog' => 'translatewizard/' . $path,
        ],
        [
            'label'    => t('translatewizard.action.restore', 'Restore original language'),
            'icon'     => 'refresh',
            'dialog'   => 'translatewizard/reset/' . $path,
            'disabled' => $hasTranslation === false,
        ],
    ];
}

Kirby::plugin('kirbydesk/translatewizard', [
    'options' => [
        'deepl.apiKey' => null,

        // Key the Project Wizard can manage in the project's .env.
        'secrets' => fn () => [
            [
                'env'    => 'DEEPL_API_KEY',
                'option' => 'deepl.apiKey',
                'label'  => t('translatewizard.secret.deepl', 'DeepL API key'),
                'help'   => t('translatewizard.secret.deepl.help', 'For “Translate page with AI”. Keys ending in :fx use DeepL Free.'),
                // valid: DeepL answers the usage request
                'check'  => fn (string $key): bool => pwSecrets::httpCheck(
                    str_ends_with($key, ':fx') ? 'https://api-free.deepl.com/v2/usage' : 'https://api.deepl.com/v2/usage',
                    ['Authorization: DeepL-Auth-Key ' . $key]
                ),
                // the kind of key, by its ending
                'type'   => fn (string $key): string => str_ends_with($key, ':fx') ? 'Free' : 'Pro',
            ],
        ],
    ],

    'areas' => [
        'site' => function () {
            return [
                // its own view button (panel.viewButtons: "translatewizard"),
                // in secondary languages only
                'buttons' => [
                    'translatewizard' => function ($model = null) {
                        if ($model === null) return null;
                        $actions = _translatewizard_actions($model);
                        if ($actions === []) return null;
                        return [
                            'icon'    => 'translatewizard-translate',
                            'title'   => t('translatewizard.button', 'Translation'),
                            'options' => $actions,
                        ];
                    },
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
                            $model = $model->update($defaultContent, $target);

                            // Drop the translated slug — a slug equal to
                            // the folder name is removed from the text file.
                            if ($model instanceof \Kirby\Cms\Page && !$model->isHomeOrErrorPage()) {
                                $model->changeSlug($model->uid(), $target);
                            }

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
            // Settings › Translation in the Project Wizard: the tree of the
            // translated fields, the choice saved (site update rights)
            [
                'pattern' => 'translatewizard/fields',
                'method'  => 'GET',
                'action'  => fn () => ['tree' => Fields::tree()],
            ],
            [
                'pattern' => 'translatewizard/fields',
                'method'  => 'POST',
                'action'  => function () {
                    $kirby = App::instance();
                    if ($kirby->user()?->role()->permissions()->for('site', 'update') !== true) {
                        throw new PermissionException(message: 'Not allowed.');
                    }
                    $values = $kirby->request()->body()->get('fields');
                    Fields::save(is_array($values) ? $values : []);
                    return ['tree' => Fields::tree()];
                },
            ],
            // Translate several pages (Project Wizard › Translation): the
            // secondary languages and, per language, the pages – with or
            // without a translation – and the characters each would cost
            // (a dry run: nothing is sent); translated then page by page
            // through (:all)/translatewizard/translate
            [
                'pattern' => 'translatewizard/batch',
                'method'  => 'GET',
                'action'  => function () {
                    $kirby = App::instance();
                    $default = $kirby->defaultLanguage();
                    if ($default === null) return ['languages' => [], 'pages' => []];
                    $languages = [];
                    foreach ($kirby->languages() as $language) {
                        if ($language->code() === $default->code()) continue;
                        $languages[] = ['code' => $language->code(), 'name' => $language->name()];
                    }
                    $pages = [];
                    foreach ($kirby->site()->index(true) as $page) {
                        $translator = new Translator(null, true);
                        $translator->translatePage($page, $default->code(), $default->code());
                        $chars = array_sum(array_map(fn ($t) => mb_strlen(strip_tags((string) $t)), $translator->sent));
                        $translated = [];
                        foreach ($languages as $language) {
                            $translated[$language['code']] = $page->version('latest')->exists($language['code']);
                        }
                        $pages[] = [
                            'path'       => $page->panel()->path(),
                            'title'      => $page->title()->value(),
                            'chars'      => $chars,
                            'translated' => $translated,
                        ];
                    }
                    return ['languages' => $languages, 'pages' => $pages];
                },
            ],
            // the DeepL account's usage this period (characters, limit);
            // null without key or when DeepL does not answer
            [
                'pattern' => 'translatewizard/usage',
                'method'  => 'GET',
                'action'  => function () {
                    $apiKey = _translatewizard_apiKey(App::instance());
                    if ($apiKey === null) return ['usage' => null];
                    try {
                        return ['usage' => (new DeepL($apiKey))->usage()];
                    } catch (Throwable $e) {
                        return ['usage' => null, 'error' => $e->getMessage()];
                    }
                },
            ],
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
