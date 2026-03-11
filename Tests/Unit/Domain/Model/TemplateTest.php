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

    #[Test]
    public function isCreativeModeReturnsTrueForCreative(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', generationMode: 'creative');
        self::assertTrue($template->isCreativeMode());
    }

    #[Test]
    public function isCreativeModeReturnsFalseForStructured(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', generationMode: 'structured');
        self::assertFalse($template->isCreativeMode());
    }

    #[Test]
    public function generationModeDefaultsToStructured(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't');
        self::assertSame('structured', $template->generationMode);
        self::assertFalse($template->isCreativeMode());
    }

    #[Test]
    public function getConfigHashChangesWhenGenerationModeChanges(): void
    {
        $a = new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: 'prompt', generationMode: 'structured');
        $b = new Template(uid: 1, title: 'T', identifier: 't', systemPrompt: 'prompt', generationMode: 'creative');

        self::assertNotSame($a->getConfigHash(), $b->getConfigHash());
    }

    #[Test]
    public function colorPropertiesDefaultToEmpty(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't');
        self::assertSame('', $template->colorPrimary);
        self::assertSame('', $template->colorSecondary);
        self::assertSame('', $template->colorBackground);
        self::assertSame('', $template->colorText);
    }

    #[Test]
    public function colorPropertiesAcceptCustomValues(): void
    {
        $template = new Template(
            uid: 1,
            title: 'T',
            identifier: 't',
            colorPrimary: '#ff0000',
            colorSecondary: '#00ff00',
            colorBackground: '#000000',
            colorText: '#ffffff',
        );
        self::assertSame('#ff0000', $template->colorPrimary);
        self::assertSame('#00ff00', $template->colorSecondary);
        self::assertSame('#000000', $template->colorBackground);
        self::assertSame('#ffffff', $template->colorText);
    }

    #[Test]
    public function withResolvedColorsInheritsFromDefaults(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't');
        $resolved = $template->withResolvedColors([
            'colorPrimary' => '#aaaaaa',
            'colorSecondary' => '#bbbbbb',
        ]);

        self::assertSame('#aaaaaa', $resolved->colorPrimary);
        self::assertSame('#bbbbbb', $resolved->colorSecondary);
        self::assertSame('#ffffff', $resolved->colorBackground);
        self::assertSame('#333333', $resolved->colorText);
    }

    #[Test]
    public function withResolvedColorsPreservesTemplateOverrides(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't', colorPrimary: '#ff0000');
        $resolved = $template->withResolvedColors([
            'colorPrimary' => '#aaaaaa',
            'colorSecondary' => '#bbbbbb',
        ]);

        self::assertSame('#ff0000', $resolved->colorPrimary);
        self::assertSame('#bbbbbb', $resolved->colorSecondary);
    }

    #[Test]
    public function withResolvedColorsUsesHardcodedFallbacks(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't');
        $resolved = $template->withResolvedColors([]);

        self::assertSame('#0062a3', $resolved->colorPrimary);
        self::assertSame('#ff8700', $resolved->colorSecondary);
        self::assertSame('#ffffff', $resolved->colorBackground);
        self::assertSame('#333333', $resolved->colorText);
    }

    #[Test]
    public function animationEnabledDefaultsToTrue(): void
    {
        $template = new Template(uid: 1, title: 'Test', identifier: 'test');
        self::assertTrue($template->animationEnabled);
    }

    #[Test]
    public function animationEnabledCanBeDisabled(): void
    {
        $template = new Template(uid: 1, title: 'Test', identifier: 'test', animationEnabled: false);
        self::assertFalse($template->animationEnabled);
    }

    #[Test]
    public function configHashIncludesAnimationEnabled(): void
    {
        $enabled = new Template(uid: 1, title: 'T', identifier: 't', animationEnabled: true);
        $disabled = new Template(uid: 1, title: 'T', identifier: 't', animationEnabled: false);
        self::assertNotSame($enabled->getConfigHash(), $disabled->getConfigHash());
    }

    #[Test]
    public function isAnimationEnabledReturnsTrueByDefault(): void
    {
        $template = new Template(uid: 1, title: 'T', identifier: 't');
        self::assertTrue($template->isAnimationEnabled());
    }
}
