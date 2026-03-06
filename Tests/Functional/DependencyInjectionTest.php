<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Functional;

use Netresearch\NrLandingpage\Controller\Backend\LandingPageWizardController;
use Netresearch\NrLandingpage\Service\BriefingService;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\ImageSearchService;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use Netresearch\NrLandingpage\Service\TemplateService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verifies all services can be resolved from the DI container.
 *
 * This test would have caught the public:false misconfiguration
 * where TYPO3 fell back to GeneralUtility::makeInstance() without
 * constructor arguments.
 */
final class DependencyInjectionTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-landingpage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function allServicesCanBeResolvedFromContainer(): void
    {
        $services = [
            LandingPageWizardController::class,
            TemplateService::class,
            BriefingService::class,
            ContentGeneratorService::class,
            ImageSearchService::class,
            PageCreatorService::class,
        ];

        foreach ($services as $className) {
            $instance = $this->get($className);
            self::assertInstanceOf($className, $instance, $className . ' could not be resolved from DI container');
        }
    }
}
