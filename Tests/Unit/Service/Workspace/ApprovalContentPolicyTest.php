<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service\Workspace;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLandingpage\Service\Workspace\ApprovalContentPolicy;
use Netresearch\NrLandingpage\Service\Workspace\WorkspaceRefusedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApprovalContentPolicy::class)]
final class ApprovalContentPolicyTest extends TestCase
{
    #[Test]
    public function theMessageNamesTheSectionByPositionAndNameAndShowsNoControlCharacters(): void
    {
        $template = new Template(1, 'T', 't');

        try {
            (new ApprovalContentPolicy())->assertSections($template, [
                ['section' => 'a', 'ctype' => 'text'],
                ['section' => "bad\nname", 'ctype' => "html\x00"],
            ]);
            self::fail('A content type outside the default set was accepted.');
        } catch (WorkspaceRefusedException $e) {
            self::assertStringStartsWith('Section 2 (bad name) uses the content type "html ", which', $e->getMessage());
            self::assertSame(1799600113, $e->getCode());
        }
    }

    #[Test]
    public function aCreativeTemplateMayCreateHtmlAndNothingElse(): void
    {
        $template = new Template(1, 'T', 't', generationMode: 'creative');
        $policy = new ApprovalContentPolicy();

        $policy->assertSections($template, [['section' => '', 'ctype' => 'html']]);
        $this->expectException(WorkspaceRefusedException::class);
        $policy->assertSections($template, [['section' => '', 'ctype' => 'list']]);
    }

    #[Test]
    public function aTemplateListIsLeftToTheSectionNormalisation(): void
    {
        $template = new Template(1, 'T', 't', allowedCTypes: ['textmedia']);

        (new ApprovalContentPolicy())->assertSections($template, [['section' => '', 'ctype' => 'text'], ['section' => '', 'ctype' => 'list']]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aTemplateListOfPageFieldsIsLeftToThePageCreation(): void
    {
        $template = new Template(1, 'T', 't', pageFields: ['description']);

        (new ApprovalContentPolicy())->assertPageFields($template, ['fe_group' => '-2']);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function emptyAndReservedPageFieldsDoNotCount(): void
    {
        $template = new Template(1, 'T', 't');

        (new ApprovalContentPolicy())->assertPageFields($template, ['fe_group' => '', 'slug' => '/x', 'doktype' => '254', 'hidden' => '0', 'description' => 'd']);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aFieldOutsideTheMetadataSetIsRefusedByName(): void
    {
        $this->expectException(WorkspaceRefusedException::class);
        $this->expectExceptionMessage('The page field "content_from_pid"');

        (new ApprovalContentPolicy())->assertPageFields(new Template(1, 'T', 't'), ['description' => 'd', 'content_from_pid' => '7']);
    }
}
