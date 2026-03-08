<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\EventListener;

use Netresearch\NrLandingpage\EventListener\AddRegenerateButtonListener;
use Netresearch\NrLandingpage\Service\LandingPageDetectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
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

        return new ModifyPageLayoutContentEvent($request, $moduleTemplate);
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
