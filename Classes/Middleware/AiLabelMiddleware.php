<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Adds a visible AI notice to a generated landing page, where a site switched
 * it on.
 *
 * A middleware rather than a template or TypoScript: the extension renders no
 * page of its own, so nothing else reaches every site's markup, and rendering
 * events differ between TYPO3 13.4 and 14.3. Off by default: what a site says
 * about itself is the site's decision.
 *
 * A page counts as generated while it carries generation data. A page an editor
 * wrote by hand and a page created before this data existed show nothing.
 */
final readonly class AiLabelMiddleware implements MiddlewareInterface
{
    private const LABEL = 'LLL:EXT:nr_landingpage/Resources/Private/Language/locallang.xlf:aiLabel.text';

    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $site = $request->getAttribute('site');
        if (!$site instanceof Site
            || !$site->getSettings()->get('nr_landingpage.aiLabel.enabled', false)
            || !$this->isGeneratedPage($request)
            || !str_starts_with($response->getHeaderLine('Content-Type'), 'text/html')
        ) {
            return $response;
        }

        $body     = (string) $response->getBody();
        $position = strripos($body, '</body>');
        if ($position === false) {
            return $response;
        }

        $notice = '<p class="nr-landingpage-ai-label">' . htmlspecialchars($this->text($site, $request), ENT_QUOTES | ENT_HTML5) . '</p>';
        $stream = new Stream('php://temp', 'rw');
        $stream->write(substr_replace($body, $notice, $position, 0));

        return $response->withBody($stream);
    }

    private function isGeneratedPage(ServerRequestInterface $request): bool
    {
        $pageInformation = $request->getAttribute('frontend.page.information');
        if (!$pageInformation instanceof PageInformation) {
            return false;
        }

        $record = $pageInformation->getPageRecord();
        // Core does not copy this column to a translation, and the overlay
        // replaces it with the translation's 0. The default language keeps it.
        $source = $record['_TRANSLATION_SOURCE'] ?? null;
        $sourceRecord = is_object($source) && method_exists($source, 'toArray') ? $source->toArray() : null;
        if (is_array($sourceRecord)) {
            $record = $sourceRecord;
        }

        $generatedAt = $record['tx_nrlandingpage_generated_at'] ?? 0;

        return is_numeric($generatedAt) && (int) $generatedAt > 0;
    }

    private function text(Site $site, ServerRequestInterface $request): string
    {
        $text = $site->getSettings()->get('nr_landingpage.aiLabel.text', '');
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        $language = $request->getAttribute('language');

        return $this->languageServiceFactory
            ->createFromSiteLanguage($language instanceof SiteLanguage ? $language : $site->getDefaultLanguage())
            ->sL(self::LABEL);
    }
}
