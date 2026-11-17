<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\EventListener;

use Netresearch\NrLandingpage\EventListener\AddRegenerateButtonListener;
use Netresearch\NrLandingpage\Service\LandingPageDetectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AddRegenerateButtonListener::class)]
final class AddRegenerateButtonListenerTest extends UnitTestCase
{
    private function createEvent(array $queryParams = []): ModifyPageLayoutContentEvent
    {
        $request = (new ServerRequest())->withQueryParams($queryParams);

        /** @var ModuleTemplate $moduleTemplate */
        $moduleTemplate = (new ReflectionClass(ModuleTemplate::class))->newInstanceWithoutConstructor();

        $arguments = [$request, $moduleTemplate];
        // TYPO3 14.3.12 added the page context as a third argument; 13.4 and
        // 14.3.11 take two. The listeners do not read the page context.
        if ((new ReflectionMethod(ModifyPageLayoutContentEvent::class, '__construct'))->getNumberOfParameters() === 3) {
            $arguments[] = (new ReflectionClass('TYPO3\\CMS\\Backend\\Context\\PageContext'))->newInstanceWithoutConstructor();
        }

        return new ModifyPageLayoutContentEvent(...$arguments);
    }

    #[Test]
    public function invokeReturnsEarlyWhenPageIdIsZero(): void
    {
        $detectionService = $this->createMock(LandingPageDetectionService::class);
        $uriBuilder = $this->createMock(UriBuilder::class);
        $iconFactory = $this->createMock(IconFactory::class);

        // If the listener returns early, it should never touch the detection service
        $detectionService->expects(self::never())->method('isGeneratedLandingPage');

        $event = $this->createEvent(['id' => 0]);

        $listener = new AddRegenerateButtonListener($detectionService, $uriBuilder, $iconFactory);
        $listener($event);
    }

    #[Test]
    public function invokeReturnsEarlyWhenNotGeneratedPage(): void
    {
        $detectionService = $this->createMock(LandingPageDetectionService::class);
        $uriBuilder = $this->createMock(UriBuilder::class);
        $iconFactory = $this->createMock(IconFactory::class);

        $detectionService->method('isGeneratedLandingPage')->with(42)->willReturn(false);

        // The UriBuilder should never be called if it's not a generated page
        $uriBuilder->expects(self::never())->method('buildUriFromRoute');

        $event = $this->createEvent(['id' => 42]);

        $listener = new AddRegenerateButtonListener($detectionService, $uriBuilder, $iconFactory);
        $listener($event);
    }
}
