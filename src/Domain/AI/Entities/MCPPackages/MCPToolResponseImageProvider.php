<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

/**
 * A tool response DTO that carries pictures next to its text: the executor appends them as image parts after the
 * rendered result (markdown or structured), as a tool that builds its own `MCPToolResponse` with `addImageContent()`
 * would. So a tool keeps one declared response type (and its schema) and still returns a picture when it has one.
 * The pictures are inline (no store of their own): the tool picture store keeps them like any inline tool picture.
 */
interface MCPToolResponseImageProvider
{
    /**
     * The pictures of the response, in order; empty when there are none.
     *
     * @return ToolResultImage[]
     */
    public function getResponseImages(): array;
}
