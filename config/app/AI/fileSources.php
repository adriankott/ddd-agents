<?php

declare(strict_types=1);

/**
 * The agent and the file sources of the application ({@see \DDD\Domain\Common\Services\Files\FileSource}: a media
 * library, the attachments of a ticket, a drive) — read as `Config::get('AI.fileSources')`
 * ({@see \DDD\Domain\AI\Entities\Drives\AgentFileSourceSettings}). An application replaces this file whole with its
 * own `config/app/AI/fileSources.php`; a missing key keeps its default.
 *
 * The drive tools of the package `agent.drive` take a file of any source by its reference `<source>/<id>`
 * (`media/901`): shown as a card, read as a document, looked at as a picture, copied into a drive.
 *
 * `findTools`: per source name, the tool that finds or lists its files. A source has no folders; when the model
 * tries to list one, the refusal names this tool.
 * `viewImages`: the limits of the tool that shows pictures to the model. `maxPerCall` (at most the executor's image
 * budget of 4), `maxSourceBytes` and `maxSourcePixels` of one source picture. The picture is rendered by the server
 * (upright, without metadata, at most 2048 pixels on its long edge), brought to the wire profile and kept by the tool
 * picture store (`AI.toolCallImageStore`): process cache, shared cache with a TTL, object store. A picture the
 * application's flow rule forbids to keep durably (a person's own file) never reaches the object store.
 */
return [
    'findTools' => [],
    'viewImages' => [
        'maxPerCall' => 4,
        'maxSourceBytes' => 20_971_520,
        'maxSourcePixels' => 50_000_000,
    ],
];
