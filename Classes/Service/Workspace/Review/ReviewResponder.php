<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace\Review;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Builds every response of the review page: the Fluid page, the labels in the
 * reviewer's language and, on all of them, the header set of ADR-0001,
 * section 5 (no cache, no referrer, no indexing, a strict content security
 * policy), so no path can forget one.
 */
class ReviewResponder
{
    public const LABEL_FILE = 'EXT:nr_landingpage/Resources/Private/Language/locallang_review.xlf';

    /**
     * Every label the templates use. A test checks this list against the
     * template and both language files.
     */
    public const LABELS = [
        'page_title', 'skip_to_content',
        'entry_title', 'entry_intro', 'entry_token', 'entry_passphrase', 'entry_name', 'entry_submit', 'entry_noscript',
        'entry_invalid', 'entry_form_expired', 'entry_limited',
        'draft_review_of', 'draft_reviewer', 'draft_leave', 'draft_expires',
        'permission_view', 'permission_comment', 'permission_edit',
        'status_approved', 'status_created',
        'section', 'field_header', 'field_subheader', 'field_bodytext', 'field_all',
        'comments_heading', 'comments_none',
        'comment_form', 'comment_about', 'comment_text', 'comment_submit',
        'suggest_summary', 'suggest_help', 'suggest_text', 'suggest_submit',
        'edit_summary', 'edit_help', 'edit_text', 'edit_submit',
        'kind_comment', 'kind_suggestion', 'kind_edit',
        'state_open', 'state_accepted', 'state_rejected', 'state_stale', 'state_applied',
        'reviewer_tag', 'editor_tag',
        'done_comment', 'done_suggest', 'done_edit',
        'error_csrf', 'error_forbidden', 'error_invalid', 'error_conflict', 'error_limited',
    ];

    public function __construct(
        private readonly ViewFactoryInterface $viewFactory,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @param array<string, mixed> $variables
     * @param list<string> $cookies Set-Cookie header values
     */
    public function page(ServerRequestInterface $request, string $template, array $variables, int $status = 200, array $cookies = [], int $retryAfter = 0): ResponseInterface
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [ExtensionManagementUtility::extPath('nr_landingpage') . 'Resources/Private/Templates/Review/'],
            layoutRootPaths: [ExtensionManagementUtility::extPath('nr_landingpage') . 'Resources/Private/Layouts/'],
            partialRootPaths: [ExtensionManagementUtility::extPath('nr_landingpage') . 'Resources/Private/Partials/Review/'],
            request: $request,
            format: 'html',
        ));
        $labels = $this->labels($request);
        $messageId = is_string($variables['messageId'] ?? null) ? $variables['messageId'] : '';
        $view->assignMultiple($variables + [
            'message' => $messageId !== '' ? ($labels[$messageId] ?? '') : '',
            'labels' => $labels,
            'lang' => $this->language($request),
            'cssUrl' => PathUtility::getPublicResourceWebPath('EXT:nr_landingpage/Resources/Public/Css/review.css'),
            'entryScriptUrl' => PathUtility::getPublicResourceWebPath('EXT:nr_landingpage/Resources/Public/JavaScript/review-entry.js'),
        ]);

        $response = new HtmlResponse($view->render($template), $status);
        if ($retryAfter > 0) {
            $response = $response->withHeader('Retry-After', (string) $retryAfter);
        }

        return $this->secured($response, $cookies);
    }

    /**
     * @param list<string> $cookies Set-Cookie header values
     */
    public function redirect(string $location, array $cookies = []): ResponseInterface
    {
        return $this->secured(new RedirectResponse($location, 303), $cookies);
    }

    /**
     * @param list<string> $cookies
     */
    private function secured(ResponseInterface $response, array $cookies): ResponseInterface
    {
        $response = $response
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        foreach ($cookies as $cookie) {
            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    public function labels(ServerRequestInterface $request): array
    {
        $languageService = $this->languageServiceFactory->create($this->language($request));
        $labels = [];
        foreach (self::LABELS as $id) {
            $label = $languageService->sL(self::LABEL_FILE . ':' . $id);
            $labels[$id] = $label !== '' ? $label : $id;
        }

        return $labels;
    }

    /**
     * German when the browser asks for it first, English otherwise.
     */
    public function language(ServerRequestInterface $request): string
    {
        $header = strtolower($request->getHeaderLine('Accept-Language'));

        return preg_match('/^\s*de(?:[-_;,\s]|$)/', $header) === 1 ? 'de' : 'en';
    }
}
