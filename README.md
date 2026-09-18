# kirby-translatewizard

DeepL-powered translator for [kirby-pagewizard](https://github.com/kirbydesk/kirby-pagewizard).
Understands pagewizard's `pwtext` and `pweditor` JSON envelopes and
translates only their text payload, leaving alignment/level/size/mode
config untouched. Walks Blocks recursively so nested items
(`pwsteplistitem`, `pwcardletsitem`, `pwfeaturelistitem`, `pwButton`, …)
are translated too.

Adds an **AI** button to page views in every secondary language with
two actions:

- **Translate page with AI** — sends the default-language content through DeepL
  and writes the result to the current secondary language.
- **Restore original language** — copies the default-language content
  onto the current language 1:1 (destructive).

## Requirements

- Kirby 5
- kirby-pagewizard ^1.0
- A DeepL API key (free tier `xxx:fx` or pro `xxx`)

## Installation

```bash
composer require kirbydesk/kirby-translatewizard
```

Or drop this repository into `site/plugins/kirby-translatewizard/`.

## Configuration

### DeepL API key

Register your DeepL API key in `site/config/config.php`:

```php
return [
    'kirbydesk.translatewizard' => [
        'deepl' => [
            'apiKey' => 'YOUR-DEEPL-API-KEY',
        ],
    ],
];
```

The key ending in `:fx` triggers the DeepL Free endpoint; any other
value hits DeepL Pro.

Get an API key at <https://www.deepl.com/pro-api>. The DeepL Free tier
allows up to 500,000 characters per month.

> **Note:** translatewizard is not compatible with
> [johannschopplich/kirby-content-translator](https://kirby.tools/docs/content-translator).
> Use one or the other, not both.

### Panel button

The **AI** view button is registered globally as `translatewizard`. It
only appears in secondary languages (Kirby's default language cannot
translate to itself).

To pick it up automatically on every page and site view, add it to
Kirby's `panel.viewButtons` config:

```php
return [
    'panel' => [
        'viewButtons' => [
            'page' => ['open', 'preview', '-', 'settings', 'translatewizard', 'languages', 'status'],
            'site' => ['preview', '-', 'settings', 'translatewizard', 'languages'],
        ],
    ],
];
```

This config only applies to blueprints that do **not** declare their
own `buttons:`. If a page blueprint declares its own list, add
`- translatewizard` to it explicitly — Kirby always prefers the
blueprint list over the config default.

## API

Direct HTTP endpoint (for scripting / CI):

```
POST /api/pages/(:all)/translatewizard/translate
Content-Type: application/json

{ "from": "de", "to": "en" }
```

Requires an authenticated Panel session.

## Development

- `src/PwtextCodec.php` — decode/encode for pwtext + pweditor envelopes
- `src/BlockWalker.php` — path-based recursive walker over nested Blocks
- `src/DeepL.php` — DeepL client (free/pro auto-detect, batched, splits
  html vs plain payloads to avoid `&amp;` escaping in plain text)
- `src/Translator.php` — orchestrator: extract → translate → write back
- `index.php` — Kirby plugin registration (options, area, dialogs, API route)
- `index.js` — Panel-side custom icon registration

## License

Proprietary — internal Kirbydesk use.
