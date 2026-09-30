# kirby-translatewizard

DeepL-powered translator for [kirby-pagewizard](https://github.com/kirbydesk/kirby-pagewizard).
Understands pagewizard's `pwtext` and `pweditor` JSON envelopes and
translates only their text payload, leaving alignment/level/size/mode
config untouched. Walks Blocks recursively, so nested items
(`pwcardletsitem`, `pwsteplistitem`, `pwButton`, …) are translated too.

## What is translated

Which fields are translated follows their **type in the blueprints**:
text, textarea, writer, markdown, list, tags and pagewizard's `pwtext` /
`pweditor` – a structure with its text columns, row by row. Everything
else – links, icons, options, files – is never sent to DeepL, and fields
with `translate: false` never either.

A page translation covers:

- the text fields of the page's **template** (its title, the meta fields …),
- the **blocks** of its blocks fields,
- the texts of its **media** (alternative text, caption, description …) –
  the page's own files always, files it uses from elsewhere only while
  they have no translation yet.

A shared block (pagewizard's `pwshared`) is only a reference on the page;
its content lives in the site and is translated there by hand.

On the first translation, a page gets a target-language slug derived from
the translated title. Once a language has its own slug it is never changed
again, so published URLs stay stable (home and error page keep their slug;
on a slug collision the current slug is kept).

## Project Wizard: Settings → Translation

With [kirby-projectwizard](https://github.com/kirbydesk/kirby-projectwizard)
the plugin gets a page of its own:

- **Translated fields** – a tree of the templates, blocks and media, each
  field with a checkbox. New templates, blocks and fields are included
  automatically; fields holding ids (`fragment`, the anchor) and the
  media's credits start switched off. The choice is stored in
  `content/.projectwizard/translate.json` (only what differs from the
  start values).
- **Translate pages** – per language the missing pages or all of them, one
  after the other in a dialog: the characters it sends first, then the
  progress (stoppable, and to be continued from there), then the result.
- **Access keys and usage** – the DeepL key (checked with DeepL: valid or
  not, Free or Pro) and the characters used this billing period.

## Panel button

The plugin brings its own view button `translatewizard`:

- In the **original language**: *Translate into: …* for each secondary
  language without a translation (a language with one is greyed out), and
  *Translate missing languages* when there are several.
- In a **secondary language**: *Translate page* while it has no
  translation, *Delete translation* once it has one – the page then shows
  the original language again (the translated slug goes with it).

Each translation asks first, with the characters it sends to DeepL.
kirby-contentwizard brings its own button (`contentwizard`).

Add the button to Kirby's `panel.viewButtons` config:

```php
return [
    'panel' => [
        'viewButtons' => [
            'page' => ['open', '-', 'settings', 'contentwizard', 'translatewizard', 'languages', 'status'],
        ],
    ],
];
```

This config only applies to blueprints that do **not** declare their own
`buttons:`. If a page blueprint declares its own list, add
`- translatewizard` to it explicitly – Kirby always prefers the blueprint
list over the config default.

## Requirements

- Kirby 5 with several languages
- kirby-pagewizard ^1.1.53
- kirby-projectwizard for the settings page (optional)
- A DeepL API key (Free `xxx:fx` or Pro `xxx`)

## Installation

```bash
composer require kirbydesk/kirby-translatewizard
```

Or drop this repository into `site/plugins/kirby-translatewizard/`.

## Configuration

### DeepL API key

Easiest: enter it in the Project Wizard under **Settings → Translation**
(admins only). It is stored as `DEEPL_API_KEY` in the project's `.env`
and never shown again in full. Alternatively set it in
`site/config/config.php` – the config option takes precedence:

```php
return [
    'kirbydesk.translatewizard' => [
        'deepl' => [
            'apiKey' => 'YOUR-DEEPL-API-KEY',
        ],
    ],
];
```

A key ending in `:fx` uses the DeepL Free endpoint, any other DeepL Pro.
Get a key at <https://www.deepl.com/pro-api>; DeepL Free allows up to
500,000 characters per month.

> **Note:** translatewizard is not compatible with
> [johannschopplich/kirby-content-translator](https://kirby.tools/docs/content-translator).
> Use one or the other, not both.

## API

All routes require an authenticated Panel session.

```
POST /api/pages/(:all)/translatewizard/translate
{ "from": "de", "to": "en" }
```

Translates one page (`from` defaults to the default language).

- `GET  /api/translatewizard/fields` – the tree of the translated fields
- `POST /api/translatewizard/fields` – `{ "fields": { "pwButton.arialabel": false } }`
- `GET  /api/translatewizard/batch` – the secondary languages, the pages
  with their translation state and estimated characters
- `GET  /api/translatewizard/usage` – DeepL's usage this period

## Development

- `src/Fields.php` – the translatable fields (blueprint types, the choice,
  the tree)
- `src/Translator.php` – orchestrator: collect → translate → write back
  (also a dry run, for the estimated characters)
- `src/BlockWalker.php` – path-based recursive walker over nested Blocks
- `src/PwtextCodec.php` – decode/encode for pwtext + pweditor envelopes
- `src/DeepL.php` – DeepL client (Free/Pro auto-detect, batched, usage)
- `index.php` – plugin registration (options, view button, dialogs, API)
- `index.js` – the Panel icon

## License

Proprietary – internal Kirbydesk use.
