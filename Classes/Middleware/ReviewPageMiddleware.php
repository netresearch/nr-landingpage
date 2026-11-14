<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Middleware;

use Netresearch\NrLandingpage\Controller\Frontend\ReviewController;
use Netresearch\NrLandingpage\Service\Workspace\WorkspaceSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * Answers everything below /nr-landingpage/review (ADR-0001, section 5)
 * before the site is resolved, so a request never reaches page resolution,
 * the page cache or the TypoScript renderer, and nothing it answers is cached.
 *
 * It passes the request on, to the site's own 404, when the review page is
 * switched off or the request is not HTTPS (unless `allowInsecureHttp`): from
 * outside, both look like a path that does not exist.
 */
final class ReviewPageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ReviewController $controller,
        private readonly WorkspaceSettings $settings,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        $base = ReviewController::BASE_PATH;
        if ($path !== $base && !str_starts_with($path, $base . '/')) {
            return $handler->handle($request);
        }
        if (!$this->settings->isEnabled()) {
            return $handler->handle($request);
        }
        $params = $request->getAttribute('normalizedParams');
        $params = $params instanceof NormalizedParams ? $params : NormalizedParams::createFromRequest($request);
        $secure = $params->isHttps();
        if (!$secure && !$this->settings->allowInsecureHttp()) {
            return $handler->handle($request);
        }

        return $this->controller->handle($request, substr($path, strlen($base)), $secure, time());
    }
}
