<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Architecture;

use Netresearch\NrLandingpage\Service\PageCreatorService;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class LayerDependencyTest
{
    public function testServicesShouldNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Controller'));
    }

    public function testNoDirectVaultAccess(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrVault'));
    }

    public function testModelsShouldNotDependOnServices(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Domain'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Service'));
    }

    public function testEventsShouldBeStandalone(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Event'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Netresearch\NrLandingpage\Service'),
                Selector::inNamespace('Netresearch\NrLandingpage\Controller'),
            );
    }

    /**
     * Services should not use GeneralUtility for service location.
     * PageCreatorService is excluded because it needs GeneralUtility::makeInstance(DataHandler::class),
     * which is an accepted trade-off since DataHandler does not support constructor injection.
     */
    public function testNoGeneralUtilityInServices(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLandingpage\Service'))
            ->excluding(Selector::classname(PageCreatorService::class))
            ->shouldNotDependOn()
            ->classes(Selector::classname('TYPO3\CMS\Core\Utility\GeneralUtility'));
    }
}
