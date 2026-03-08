<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Domain\Model;

use Netresearch\NrLandingpage\Domain\Model\Template;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(Template::class)]
final class TemplateTest extends UnitTestCase
{
    #[Test]
    public function gettersReturnConstructorValues(): void
    {
        $template = new Template(
            uid: 1,
            title: 'Event LP',
            identifier: 'event-lp',
            description: 'Event landing page',
            llmConfiguration: 5,
            systemPrompt: 'You create event pages',
            allowedCTypes: ['text', 'header', 'textmedia'],
            pageFields: ['seo_title', 'description'],
            referencePages: [10, 20],
            briefingMode: 'optional',
            publishMode: 'hidden',
            beGroups: [1, 2],
            backendLayout: 'pagets__default',
            promptOptimizerContext: 'Brand: Acme',
            promptOptimizerMetaPrompt: 'Custom meta-prompt',
            imageTask: 42,
        );

        self::assertSame(1, $template->uid);
        self::assertSame('Event LP', $template->title);
        self::assertSame('event-lp', $template->identifier);
        self::assertSame('Event landing page', $template->description);
        self::assertSame(5, $template->llmConfiguration);
        self::assertSame('You create event pages', $template->systemPrompt);
        self::assertSame(['text', 'header', 'textmedia'], $template->allowedCTypes);
        self::assertSame(['seo_title', 'description'], $template->pageFields);
        self::assertSame([10, 20], $template->referencePages);
        self::assertSame('optional', $template->briefingMode);
        self::assertSame('hidden', $template->publishMode);
        self::assertSame([1, 2], $template->beGroups);
        self::assertSame('pagets__default', $template->backendLayout);
        self::assertSame('Brand: Acme', $template->promptOptimizerContext);
        self::assertSame('Custom meta-prompt', $template->promptOptimizerMetaPrompt);
        self::assertSame(42, $template->imageTask);
    }

    #[Test]
    public function isBriefingRequiredReturnsTrueForRequired(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', briefingMode: 'required');
        self::assertTrue($template->isBriefingRequired());
        self::assertFalse($template->isBriefingSkippable());
    }

    #[Test]
    public function isBriefingSkippableReturnsTrueForOptional(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', briefingMode: 'optional');
        self::assertTrue($template->isBriefingSkippable());
        self::assertFalse($template->isBriefingRequired());
    }

    #[Test]
    public function isBriefingDisabledReturnsTrueForNone(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', briefingMode: 'none');
        self::assertTrue($template->isBriefingDisabled());
    }

    #[Test]
    public function hasReferencePagesReturnsFalseWhenEmpty(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't');
        self::assertFalse($template->hasReferencePages());
    }

    #[Test]
    public function hasReferencePagesReturnsTrueWhenSet(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', referencePages: [10]);
        self::assertTrue($template->hasReferencePages());
    }

    #[Test]
    public function imageTaskDefaultsToZero(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't');
        self::assertSame(0, $template->imageTask);
        self::assertFalse($template->hasImageTask());
    }

    #[Test]
    public function hasImageTaskReturnsTrueWhenSet(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', imageTask: 5);
        self::assertSame(5, $template->imageTask);
        self::assertTrue($template->hasImageTask());
    }

    #[Test]
    public function getConfigHashReturnsConsistentHash(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: 'prompt', allowedCTypes: ['text']);
        self::assertSame($template->getConfigHash(), $template->getConfigHash());
    }

    #[Test]
    public function getConfigHashChangesWhenSystemPromptChanges(): void
    {
        $a = new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: 'prompt A', allowedCTypes: ['text'], pageFields: [], backendLayout: '', llmConfiguration: 0, imageTask: 0);
        $b = new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: 'prompt B', allowedCTypes: ['text'], pageFields: [], backendLayout: '', llmConfiguration: 0, imageTask: 0);

        self::assertNotSame($a->getConfigHash(), $b->getConfigHash());
    }

    #[Test]
    public function getConfigHashChangesWhenCTypesChange(): void
    {
        $a = new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: 'prompt', allowedCTypes: ['text'], pageFields: [], backendLayout: '', llmConfiguration: 0, imageTask: 0);
        $b = new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: 'prompt', allowedCTypes: ['header'], pageFields: [], backendLayout: '', llmConfiguration: 0, imageTask: 0);

        self::assertNotSame($a->getConfigHash(), $b->getConfigHash());
    }

    #[Test]
    public function getConfigHashIsDeterministicRegardlessOfCtypeOrder(): void
    {
        $a = new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: 'prompt', allowedCTypes: ['text', 'header'], pageFields: [], backendLayout: '', llmConfiguration: 0, imageTask: 0);
        $b = new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: 'prompt', allowedCTypes: ['header', 'text'], pageFields: [], backendLayout: '', llmConfiguration: 0, imageTask: 0);

        self::assertSame($a->getConfigHash(), $b->getConfigHash());
    }
}
