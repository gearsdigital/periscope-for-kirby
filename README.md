# Kirby Periscope

A configurable log file viewer for the Kirby Panel. Gives editors and
developers a "Logs" menu item that tails one or more log files straight in
the browser. No SSH, no FTP client, no tailing logs on a server console.

![periscope-banner.webp](media/periscope-banner.webp)

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Files](#files)
  - [Folders](#folders)
  - [Minimum log level](#minimum-log-level)
  - [Options reference](#options-reference)
- [Features](#features)
- [Security](#security)
- [Development](#development)
- [License](#license)

## Requirements

- Kirby CMS >= 4.0
- PHP >= 8.3

## Installation

### Composer

```bash
composer require gearsdigital/periscope-for-kirby
```

### Manual

Download and copy this repository to `/site/plugins/periscope-for-kirby`.

## Configuration

```php
// config/config.php
return [
    'gearsdigital.periscope' => [
        'files' => [
            ['label' => 'PHP Errors', 'path' => '/var/log/php_errorlog'],
            ['label' => 'Spam Guard', 'path' => '/var/log/spam.log', 'minLevel' => 'warning'],
        ],
        'folders' => [
            [
                'label'    => 'Kirbylog',
                'path'     => fn () => kirby()->root('logs'),
                'pattern'  => '*.log',
                'minLevel' => 'warning',
            ],
        ],
        'minLevel' => 'error',
    ],
];
```

### Files

Each entry in `files` adds one selectable log file to the panel. `path` can
be a string or a closure: use a closure when the path depends on `kirby()`
(which isn't ready yet while `config.php` is being loaded, same caveat as
[`johannschopplich/kirbylog`](https://github.com/johannschopplich/kirbylog)).

### Folders

Instead of listing single files, point at a whole folder, handy for daily
rotating logs (e.g. `johannschopplich/kirbylog` writes one file per day).
Every file in the folder matching `pattern` (glob, default `*.log`) becomes
its own selectable entry, labelled `<label> – <filename>`, newest first. The
folder is re-scanned on every request, so a new day's file shows up without
a restart.

`pattern` is passed straight to PHP's [`glob()`](https://www.php.net/glob),
so standard glob syntax works. `{a,b}` brace expansion is supported too
(`GLOB_BRACE` is only enabled when the pattern actually contains `{`, since
it isn't reliably available on every platform):

| Pattern | Matches |
|---------|---------|
| `*.log` | All `.log` files (default) |
| `kirby-*.log` | Only files starting with `kirby-`, e.g. `kirby-2026-09-08.log` |
| `access.log*` | `access.log`, `access.log.1`, `access.log.gz`, ... |
| `[0-9]*.log` | Files starting with a digit |
| `202?-*.log` | `?` matches a single character, e.g. `2026-01.log` |
| `*.{log,txt}` | Multiple extensions, e.g. `.log` and `.txt` |

### Minimum log level

`minLevel` discards entries below the given severity **on the server**,
before they're counted or paginated. It's not just a UI filter: the browser
never receives them. Set it globally or per file/folder. Values, from most
to least severe: `critical`, `error`, `warning`, `notice`, `info`, `debug`.
`null` (default) disables the filter.

### Options reference

| Option | Default | Description |
|--------|---------|--------------|
| `files` | `[]` | List of `{label, path, minLevel?}` entries, one per log file |
| `folders` | `[]` | List of `{label, path, pattern?, minLevel?}` entries, one per log folder |
| `defaultEntries` | `200` | Entries loaded per page on first load |
| `maxEntries` | `2000` | Hard upper bound for `defaultEntries` and the panel's page-size selector |
| `minLevel` | `null` | Global minimum log level (see above), overridable per file/folder |

Example for an environment-specific config file (e.g. `site/config/config.example.com.php`)
using every option:

```php
// site/config/config.example.com.php
return [
    'gearsdigital.periscope' => [
        'files' => [
            ['label' => 'PHP Errors', 'path' => '/var/log/php_errorlog'],
            ['label' => 'Spam Guard', 'path' => '/var/log/spam.log', 'minLevel' => 'warning'],
        ],
        'folders' => [
            [
                'label'    => 'Server-Logs',
                'path'     => fn () => '/home/log',
                'pattern'  => '*.log',
                'minLevel' => 'warning',
            ],
        ],
        'defaultEntries' => 200,
        'maxEntries'     => 2000,
        'minLevel'       => 'error',
    ],
];
```

## Features

- Multi-line entries (e.g. embedded JSON stack traces) are parsed as a
  single entry instead of being cut off at an arbitrary line count.
- Level-aware styling and filter pills (critical/error/warning/notice/info/debug).
- Free-text search across visible entries.
- Copy a single entry, or all currently visible entries, to the clipboard.
- Download the raw log file.
- Optional auto-refresh (10s).
- Pagination that reads from the end of the file backwards, without loading
  the whole file into memory.
- Panel UI translated into German, English, French, Dutch and Polish
  (follows the Panel's language setting automatically).

## Security

The panel frontend only ever sends a file `id`, never a path. The actual
path always comes from the server-side config. This rules out arbitrary
file reads via the panel API, regardless of what a client sends.

## Development

```bash
# Development with watch mode
npm run dev

# Production build
npm run build
```

## License

[MIT](LICENSE)
