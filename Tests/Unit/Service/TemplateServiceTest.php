<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\TemplateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(TemplateService::class)]
final class TemplateServiceTest extends UnitTestCase
{
    #[Test]
    public function getAvailableCTypesReturnsItemsFromTca(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] = [
            ['label' => 'Header', 'value' => 'header'],
            ['label' => 'Text', 'value' => 'text'],
            ['label' => '--div--', 'value' => '--div--'],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailableCTypes($params);

        self::assertCount(2, $params['items']);
        self::assertSame('header', $params['items'][0]['value']);
        self::assertSame('text', $params['items'][1]['value']);
    }

    #[Test]
    public function getAvailableCTypesSkipsEmptyValues(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] = [
            ['label' => 'Header', 'value' => 'header'],
            ['label' => 'Empty', 'value' => ''],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailableCTypes($params);

        self::assertCount(1, $params['items']);
    }

    #[Test]
    public function getAvailablePageFieldsExcludesSystemFields(): void
    {
        $GLOBALS['TCA']['pages']['columns'] = [
            'seo_title' => ['label' => 'SEO Title', 'config' => ['type' => 'input']],
            'uid' => ['label' => 'UID', 'config' => ['type' => 'input']],
            'pid' => ['label' => 'PID', 'config' => ['type' => 'input']],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        self::assertCount(1, $params['items']);
        self::assertSame('seo_title', $params['items'][0]['value']);
    }

    #[Test]
    public function getAvailablePageFieldsExcludesPassthroughFields(): void
    {
        $GLOBALS['TCA']['pages']['columns'] = [
            'seo_title' => ['label' => 'SEO Title', 'config' => ['type' => 'input']],
            'internal' => ['label' => 'Internal', 'config' => ['type' => 'passthrough']],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        self::assertCount(1, $params['items']);
    }

    #[Test]
    public function getAvailablePageFieldsIncludesFieldNameInLabel(): void
    {
        $GLOBALS['TCA']['pages']['columns'] = [
            'seo_title' => ['label' => 'SEO Title', 'config' => ['type' => 'input']],
        ];

        $service = new TemplateService($this->createMock(ConnectionPool::class));
        $params = ['items' => []];
        $service->getAvailablePageFields($params);

        self::assertStringContainsString('[seo_title]', $params['items'][0]['label']);
    }
}
