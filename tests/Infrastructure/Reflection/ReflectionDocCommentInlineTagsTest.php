<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Reflection;

use DDD\Infrastructure\Reflection\ReflectionDocComment;
use PHPUnit\Framework\TestCase;

/**
 * Inline `{@see …}` / `{@link …}` tags in descriptions (OpenAPI summaries, property and enum descriptions) render as
 * the referenced name in backticks, a URL as is — before, the tag stripping cut the text at their closing brace.
 */
class ReflectionDocCommentInlineTagsTest extends TestCase
{
    public function testAReferenceRendersAsItsNameInBackticks(): void
    {
        self::assertSame('`validateBusinessAddress`', ReflectionDocComment::renderReference('validateBusinessAddress()'));
        self::assertSame('`updateShortUrl`', ReflectionDocComment::renderReference('\App\Presentation\ShortUrlsController::updateShortUrl'));
        self::assertSame('`AccountChannelAddress`', ReflectionDocComment::renderReference('\App\Domain\AI\Entities\Channels\AccountChannelAddress'));
        self::assertSame('`KIND_CONVERSATION`', ReflectionDocComment::renderReference('AgentDaySuggestedAction::KIND_CONVERSATION'));
        self::assertSame('`timezone`', ReflectionDocComment::renderReference('Location::$timezone'));
        self::assertSame('https://example.com/docs', ReflectionDocComment::renderReference('https://example.com/docs'));
    }

    public function testTheDescriptionKeepsTheTextAroundTheReferences(): void
    {
        $docComment = new ReflectionDocComment(<<<'DOC'
            /**
             * Persist the address — ONLY after
             * {@see validateBusinessAddress()} returned `ADDRESS_OK`. Mirrors {@see \App\X\Controller::updateAddress the save}.
             * One of {@see Kinds::KINDS}; see {@link https://example.com/a}.
             *
             * @example {"locationId": 1}
             */
            DOC);

        $description = $docComment->getDescription(includeExamples: false);

        self::assertStringContainsString("ONLY after\n`validateBusinessAddress` returned `ADDRESS_OK`.", $description);
        self::assertStringContainsString('Mirrors `updateAddress`.', $description);
        self::assertStringContainsString('One of `KINDS`; see https://example.com/a.', $description);
        self::assertStringNotContainsString('}', $description);
        self::assertStringNotContainsString('@see', $description);
    }

    public function testAPropertyDescriptionRendersItsReferences(): void
    {
        $docComment = new ReflectionDocComment(<<<'DOC'
            /** @var string|null One of {@see AgentDaySuggestedAction::DEEP_LINK_KEYS} for a {@see AgentDaySuggestedAction::KIND_DEEP_LINK} action. */
            DOC);

        self::assertSame(
            'One of `DEEP_LINK_KEYS` for a `KIND_DEEP_LINK` action.',
            $docComment->getDescription(forProperty: true, includeExamples: false)
        );
    }

    public function testATextWithoutInlineTagsIsUnchanged(): void
    {
        $docComment = new ReflectionDocComment("/**\n * Plain text with a mail@example.com and {braces}.\n */");

        self::assertSame('Plain text with a mail@example.com and {braces}.', $docComment->getDescription());
    }
}
