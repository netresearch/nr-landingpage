<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Middleware;

use Netresearch\NrLandingpage\Middleware\AiLabelMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Frontend\Page\PageInformation;

#[CoversClass(AiLabelMiddleware::class)]
final class AiLabelMiddlewareTest extends TestCase
{
    private const PAGE = '<html><body><p>Inhalt</p></body></html>';

    #[Test]
    public function aGeneratedPageGetsTheBuiltInNoticeBeforeTheEndOfTheBody(): void
    {
        $body = $this->process(['enabled' => true], ['tx_nrlandingpage_generated_at' => 1700000000]);

        self::assertSame('<html><body><p>Inhalt</p><p class="nr-landingpage-ai-label">Built-in text</p></body></html>', $body);
    }

    #[Test]
    public function aSiteSetsItsOwnTextAndItIsEscaped(): void
    {
        $body = $this->process(['enabled' => true, 'text' => 'Mit <KI> erstellt'], ['tx_nrlandingpage_generated_at' => 1700000000]);

        self::assertStringContainsString('>Mit &lt;KI&gt; erstellt</p>', $body);
    }

    #[Test]
    public function nothingChangesWhileTheSiteHasNotSwitchedItOn(): void
    {
        self::assertSame(self::PAGE, $this->process([], ['tx_nrlandingpage_generated_at' => 1700000000]));
    }

    #[Test]
    public function aPageWrittenByHandShowsNothing(): void
    {
        self::assertSame(self::PAGE, $this->process(['enabled' => true], ['tx_nrlandingpage_generated_at' => 0]));
    }

    #[Test]
    public function aPageWithoutGenerationDataShowsNothing(): void
    {
        self::assertSame(self::PAGE, $this->process(['enabled' => true], []));
    }

    #[Test]
    public function aResponseThatIsNoHtmlStaysAsItIs(): void
    {
        self::assertSame(self::PAGE, $this->process(['enabled' => true], ['tx_nrlandingpage_generated_at' => 1], 'application/json'));
    }

    #[Test]
    public function aRequestWithoutAPageStaysAsItIs(): void
    {
        $request = (new ServerRequest('https://example.test/'))->withAttribute('site', $this->site(['enabled' => true]));

        self::assertSame(self::PAGE, (string) $this->middleware()->process($request, $this->handler('text/html'))->getBody());
    }

    #[Test]
    public function aTranslationOfAGeneratedPageShowsTheNotice(): void
    {
        $source = new class {
            /**
             * @return array<string, int>
             */
            public function toArray(): array
            {
                return ['tx_nrlandingpage_generated_at' => 1700000000];
            }
        };

        $body = $this->process(['enabled' => true], ['tx_nrlandingpage_generated_at' => 0, '_TRANSLATION_SOURCE' => $source]);

        self::assertStringContainsString('nr-landingpage-ai-label', $body);
    }

    #[Test]
    public function theMiddlewareRunsWhereThePageRecordIsAlreadyOnTheRequest(): void
    {
        $stack = (string) file_get_contents(__DIR__ . '/../../../Configuration/RequestMiddlewares.php');

        self::assertMatchesRegularExpression("/'after'\s*=> \[\s*'typo3\/cms-frontend\/shortcut-and-mountpoint-redirect',/", $stack);
        self::assertMatchesRegularExpression("/'before' => \[\s*'typo3\/cms-frontend\/content-length-headers',/", $stack);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $pageRecord
     */
    private function process(array $settings, array $pageRecord, string $contentType = 'text/html; charset=utf-8'): string
    {
        $pageInformation = new PageInformation();
        $pageInformation->setPageRecord($pageRecord);

        $request = (new ServerRequest('https://example.test/'))
            ->withAttribute('site', $this->site($settings))
            ->withAttribute('frontend.page.information', $pageInformation);

        return (string) $this->middleware()->process($request, $this->handler($contentType))->getBody();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function site(array $settings): Site
    {
        return new Site('main', 1, [
            'base'      => 'https://example.test/',
            'languages' => [['languageId' => 0, 'locale' => 'en_US.UTF-8', 'base' => '/', 'title' => 'English']],
        ], SiteSettings::createFromSettingsTree(['nr_landingpage' => ['aiLabel' => $settings]]));
    }

    private function middleware(): AiLabelMiddleware
    {
        $language = self::createStub(LanguageService::class);
        $language->method('sL')->willReturn('Built-in text');
        $factory = self::createStub(LanguageServiceFactory::class);
        $factory->method('createFromSiteLanguage')->willReturn($language);

        return new AiLabelMiddleware($factory);
    }

    private function handler(string $contentType): RequestHandlerInterface
    {
        return new class ($contentType, self::PAGE) implements RequestHandlerInterface {
            public function __construct(private readonly string $contentType, private readonly string $page) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $stream = new Stream('php://temp', 'rw');
                $stream->write($this->page);

                return (new Response($stream))->withHeader('Content-Type', $this->contentType);
            }
        };
    }
}
