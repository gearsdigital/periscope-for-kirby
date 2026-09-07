<?php

namespace Gearsdigital\Periscope;

use Kirby\Cms\App as Kirby;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Http\Response;
use Kirby\Toolkit\Str;

require_once __DIR__ . '/lib/helpers.php';

/**
 * Custom panel view ("Logs" menu item) that displays a list of log files
 * defined via config.
 *
 * Configuration in site/config/config*.php:
 *
 *   'gearsdigital.periscope' => [
 *       'files' => [
 *           ['label' => 'PHP Errors', 'path' => '/var/log/php_errorlog'],
 *           // Overridable per file, see 'minLevel' below:
 *           ['label' => 'Spam Guard', 'path' => '...', 'minLevel' => 'warning'],
 *       ],
 *       // Whole folders instead of single files: useful for daily rotating
 *       // logs (e.g. johannschopplich/kirbylog writes one file per day).
 *       // Every file in the folder matching 'pattern' (glob, default
 *       // '*.log') becomes its own selectable log entry
 *       // ("<label> – <filename>"), newest first. Re-read on every
 *       // request, so a new file shows up without a restart.
 *       'folders' => [
 *           [
 *               'label'    => 'Kirbylog',
 *               'path'     => fn () => kirby()->root('logs'),
 *               'pattern'  => '*.log', // optional
 *               'minLevel' => 'warning', // optional, same as 'files'
 *           ],
 *       ],
 *       // Minimum level from which entries are shown at all (global,
 *       // overridable per file/folder via 'minLevel' above). Anything
 *       // below is discarded server-side before it ever reaches the
 *       // browser - not just a UI filter. Values (descending severity):
 *       // 'critical', 'error', 'warning', 'notice', 'info', 'debug'.
 *       // null = no filter.
 *       'minLevel' => 'error',
 *   ],
 *
 * `path` can be a string or a closure (closure needed when the path depends
 * on kirby() - see johannschopplich/kirbylog's README, same problem: kirby()
 * isn't ready yet while config.php is being loaded).
 *
 * Important for security: the frontend only ever sends an `id`, never a
 * path. The actual path comes exclusively from the server config (see
 * resolveFile() in lib/helpers.php) - so arbitrary file reads via the panel
 * API are ruled out.
 *
 * Log entries are split into individual entries server-side (see
 * parseEntries() in lib/helpers.php), rather than cut off by physical line
 * count. Monolog & co. write multi-line entries (e.g. embedded JSON); a
 * plain "last N lines" would cut such entries off mid-way and render them
 * broken.
 */
Kirby::plugin('gearsdigital/periscope', [
    'options' => [
        'files'          => [],
        'folders'        => [],
        'defaultEntries' => 200,
        'maxEntries'     => 2000,
        'minLevel'       => null,
    ],

    'areas' => [
        'log-viewer' => function () {
            return [
                'label' => 'Logs',
                'icon'  => 'terminal',
                'menu'  => true,
                'link'  => 'log-viewer',
                'views' => [
                    [
                        'pattern' => 'log-viewer',
                        'action'  => function () {
                            return [
                                'component' => 'k-periscope-view',
                                'title'     => 'Logs',
                                'props'     => [
                                    'files' => array_map(
                                        fn (array $file) => ['id' => $file['id'], 'label' => $file['label']],
                                        configuredFiles()
                                    ),
                                    'defaultEntries' => (int)kirby()->option('gearsdigital.periscope.defaultEntries', 200),
                                ],
                            ];
                        },
                    ],
                ],
            ];
        },
    ],

    'api' => [
        'routes' => [
            [
                // No 'auth' via the standard API authentication: that also
                // enforces an X-CSRF header on top of the session, which a
                // plain browser download link (<a href> instead of the
                // panel's $api fetch) can't send. A plain session check is
                // enough here instead - identical to the panel access
                // permission, just without the CSRF requirement, as is
                // common for real file downloads (cf. login.php/system.php
                // in Kirby core).
                'pattern' => 'log-viewer/files/(:any)/download',
                'method'  => 'GET',
                'auth'    => false,
                'action'  => function (string $id) {
                    $user = kirby()->user();
                    if ($user === null || $user->role()->permissions()->for('access', 'panel') === false) {
                        throw new PermissionException('Access denied.');
                    }

                    $file = resolveFile($id);
                    $path = $file['path'];

                    if (is_file($path) === false || is_readable($path) === false) {
                        throw new NotFoundException('Log file not available.');
                    }

                    $filename = Str::slug($file['label']) . '-' . basename($path);

                    return Response::download($path, $filename);
                },
            ],
            [
                'pattern' => 'log-viewer/files/(:any)',
                'method'  => 'GET',
                'action'  => function (string $id) {
                    $file = resolveFile($id);
                    $path = $file['path'];

                    $exists   = is_file($path);
                    $readable = $exists && is_readable($path);

                    $limit    = clampEntries((int)get('limit', kirby()->option('gearsdigital.periscope.defaultEntries', 200)));
                    $offset   = max(0, (int)get('offset', 0));
                    $minLevel = $file['minLevel'] ?? kirby()->option('gearsdigital.periscope.minLevel');

                    $entries = [];
                    $hasMore = false;

                    if ($readable) {
                        // Rough estimate of how many bytes need to be read
                        // for the requested depth (~4 KB/entry), hard-capped.
                        // ponytail: heuristic instead of exact re-reading -
                        // may be too tight for very large single entries
                        // (e.g. huge JSON dumps), in which case the 8 MB
                        // upper bound simply kicks in.
                        $maxBytes = min(8_000_000, max(200_000, ($offset + $limit) * 4_000));
                        $chunk    = readTailChunk($path, $maxBytes);
                        $entries  = parseEntries($chunk['text'], $chunk['truncated']);

                        // Server-side level filter: discards entries below
                        // the minimum level for good before counting/pagination
                        // kick in - not just a UI filter, the client never
                        // sees them.
                        if ($minLevel !== null) {
                            $minSeverity = levelSeverity($minLevel);
                            $entries     = array_values(array_filter(
                                $entries,
                                fn (array $e) => levelSeverity($e['level']) <= $minSeverity
                            ));
                        }

                        $total   = count($entries);
                        $hasMore = ($offset + $limit) < $total || $chunk['truncated'];
                        $entries = array_slice(
                            $entries,
                            max(0, $total - $offset - $limit),
                            min($limit, max(0, $total - $offset))
                        );
                    }

                    return [
                        'id'       => $file['id'],
                        'label'    => $file['label'],
                        'exists'   => $exists,
                        'readable' => $readable,
                        'modified' => $exists ? date('c', filemtime($path)) : null,
                        'size'     => $exists ? filesize($path) : null,
                        'minLevel' => $minLevel,
                        'entries'  => $entries,
                        'hasMore'  => $hasMore,
                    ];
                },
            ],
        ],
    ],
]);
