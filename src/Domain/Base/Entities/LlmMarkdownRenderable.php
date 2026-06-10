<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

/**
 * A domain object (Entity / ValueObject / Set) that can render ITSELF as compact, LLM-/human-friendly Markdown.
 *
 * The counterpart to {@see \DDD\Presentation\Base\Dtos\RequestDto::OUTPUT_FORMAT_LLM}: an endpoint that opts into
 * the `outputFormat=llm` shape returns `$payload->toLlmMarkdown()` instead of the full structured payload. The
 * rendering belongs ON the object (it knows its own most legible shape — a rankings matrix, a listings table, a
 * reputation summary) and is a pure fold over the object's own data; no repo/persistence contact.
 */
interface LlmMarkdownRenderable
{
    /** Render this object as a self-contained Markdown block (with its own legend where an encoding is used). */
    public function toLlmMarkdown(): string;
}
