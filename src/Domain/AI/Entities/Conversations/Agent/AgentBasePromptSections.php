<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

/**
 * Leaves named `## ` sections out of a base prompt text without touching any other byte: a section runs from its
 * `## ` heading line to the line before the next `## ` heading outside a code fence (or the end), its trailing blank
 * lines included. The heading is named by its text after `## `, with or without a leading `[surface=…]` marker
 * (`Widgets` names `## [surface=screen] Widgets`). No name, or no section of that name: the text comes back unchanged,
 * byte for byte. Pure.
 */
class AgentBasePromptSections
{
    /**
     * `$promptText` without the sections whose heading `$sectionHeadings` names.
     *
     * @param string[] $sectionHeadings
     */
    public static function dropSections(string $promptText, array $sectionHeadings): string
    {
        $droppedHeadings = [];
        foreach ($sectionHeadings as $sectionHeading) {
            $trimmedHeading = trim((string)$sectionHeading);
            if ($trimmedHeading !== '') {
                $droppedHeadings[$trimmedHeading] = true;
            }
        }
        if ($droppedHeadings === [] || !str_contains($promptText, '## ')) {
            return $promptText;
        }
        $lines = explode("\n", $promptText);
        $keptLines = [];
        $insideCodeFence = false;
        $insideDroppedSection = false;
        $droppedAnything = false;
        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $insideCodeFence = !$insideCodeFence;
            } elseif (!$insideCodeFence && str_starts_with($line, '## ')) {
                $insideDroppedSection = isset($droppedHeadings[static::readHeadingName($line)])
                    || isset($droppedHeadings[trim(substr($line, 3))]);
                $droppedAnything = $droppedAnything || $insideDroppedSection;
            }
            if (!$insideDroppedSection) {
                $keptLines[] = $line;
            }
        }
        return $droppedAnything ? implode("\n", $keptLines) : $promptText;
    }

    /** The name of a `## ` heading line: its text without a leading `[dimension=…]` marker. */
    public static function readHeadingName(string $headingLine): string
    {
        $headingText = trim(substr($headingLine, 3));
        return trim((string)preg_replace('/^\[[^\]]*\]\s*/', '', $headingText));
    }

    /**
     * The section headings of a base prompt text, as {@see dropSections()} names them (outside code fences, in order).
     *
     * @return string[]
     */
    public static function findSectionHeadings(string $promptText): array
    {
        $sectionHeadings = [];
        $insideCodeFence = false;
        foreach (explode("\n", $promptText) as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $insideCodeFence = !$insideCodeFence;
            } elseif (!$insideCodeFence && str_starts_with($line, '## ')) {
                $sectionHeadings[] = static::readHeadingName($line);
            }
        }
        return $sectionHeadings;
    }
}
