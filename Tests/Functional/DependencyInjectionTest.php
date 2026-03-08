<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Functional;

use Netresearch\NrLandingpage\Controller\Backend\LandingPageWizardController;
use Netresearch\NrLandingpage\Service\BriefingService;
use Netresearch\NrLandingpage\Service\ContentGeneratorService;
use Netresearch\NrLandingpage\Service\ImageProviderService;
use Netresearch\NrLandingpage\Service\ImageSearchService;
use Netresearch\NrLandingpage\Service\PageCreatorService;
use Netresearch\NrLandingpage\Service\PromptOptimizerService;
use Netresearch\NrLandingpage\Service\TemplateService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionNamedType;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verifies all services can be resolved from the DI container
 * AND via GeneralUtility::makeInstance() (which TYPO3 uses for
 * backend module controllers and itemsProcFunc classes at runtime).
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
            ImageProviderService::class,
            ImageSearchService::class,
            PageCreatorService::class,
            PromptOptimizerService::class,
        ];

        foreach ($services as $className) {
            $instance = $this->get($className);
            self::assertInstanceOf($className, $instance, $className . ' could not be resolved from DI container');
        }
    }

    /**
     * TYPO3 backend module controllers and itemsProcFunc classes are
     * instantiated via GeneralUtility::makeInstance(), NOT the DI container.
     * This test catches the exact bug that slipped through 3 review cycles:
     * adding a required constructor parameter that makeInstance cannot resolve.
     */
    #[Test]
    public function controllerCanBeInstantiatedViaMakeInstance(): void
    {
        $instance = GeneralUtility::makeInstance(LandingPageWizardController::class);
        self::assertInstanceOf(LandingPageWizardController::class, $instance);
    }

    /**
     * Verifies each constructor parameter of the controller can be resolved
     * individually from the DI container with the correct type.
     * Catches argument-order mismatches that autowiring hides but
     * GeneralUtility::makeInstance() does not.
     */
    #[Test]
    public function controllerConstructorParametersMatchContainerTypes(): void
    {
        $reflection = new ReflectionClass(LandingPageWizardController::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type, 'Parameter $' . $param->getName() . ' must have a named type');
            \assert($type instanceof ReflectionNamedType);

            if ($type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();
            if ($type->allowsNull()) {
                // Nullable services may not be registered — skip
                continue;
            }

            $instance = $this->get($className);
            self::assertInstanceOf(
                $className,
                $instance,
                sprintf(
                    'Constructor parameter #%d ($%s) expects %s but container returned %s',
                    $param->getPosition(),
                    $param->getName(),
                    $className,
                    get_class($instance),
                ),
            );
        }
    }

    #[Test]
    public function templateServiceCanBeInstantiatedViaMakeInstance(): void
    {
        $instance = GeneralUtility::makeInstance(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $instance);
    }
}
