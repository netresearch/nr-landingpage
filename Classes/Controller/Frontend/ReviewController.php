<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Controller\Frontend;

use Netresearch\NrLandingpage\Domain\Model\Workspace\CommentKind;
use Netresearch\NrLandingpage\Domain\Model\Workspace\DraftStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Domain\Model\Workspace\ReviewComment;
use Netresearch\NrLandingpage\Service\Workspace\CommentRepository;
use Netresearch\NrLandingpage\Service\Workspace\Review\EnterStatus;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewAuthenticator;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewContext;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewCookies;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewInput;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewRateLimits;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewRefusedException;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewResponder;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewService;
use Netresearch\NrLandingpage\Service\Workspace\Review\ReviewTokens;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * The review page for share links (ADR-0001, section 5): no login, one draft
 * per link. Everything it answers without a valid session is the same entry
 * page with the same 404.
 */
final class ReviewController
{
    public const BASE_PATH = '/nr-landingpage/review';

    public function __construct(
        private readonly ReviewAuthenticator $authenticator,
        private readonly ReviewService $reviewService,
        private readonly CommentRepository $comments,
        private readonly ReviewTokens $tokens,
        private readonly ReviewRateLimits $limits,
        private readonly ReviewResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request, string $path, bool $secure, int $now): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        return match (true) {
            $path === '' && ($method === 'GET' || $method === 'HEAD') => $this->entryPage($request, $secure, $now, 200),
            $path === '/enter' && $method === 'POST' => $this->enter($request, $secure, $now),
            $path === '/draft' && ($method === 'GET' || $method === 'HEAD') => $this->draftPage($request, $secure, $now),
            $path === '/comment' && $method === 'POST' => $this->write($request, $secure, $now, 'comment'),
            $path === '/suggest' && $method === 'POST' => $this->write($request, $secure, $now, 'suggest'),
            $path === '/edit' && $method === 'POST' => $this->write($request, $secure, $now, 'edit'),
            $path === '/leave' && $method === 'POST' => $this->leave($request, $secure, $now),
            default => $this->entryPage($request, $secure, $now, 404, 'entry_invalid'),
        };
    }

    /**
     * The entry page. Every render of it, the 200 and every 404 alike, sets
     * the entry cookie (reusing the value the request carried), so a refusal
     * looks the same whatever its cause.
     */
    private function entryPage(ServerRequestInterface $request, bool $secure, int $now, int $status, string $messageId = '', int $retryAfter = 0, string $name = ''): ResponseInterface
    {
        $entryValue = ReviewCookies::read($request->getCookieParams(), ReviewCookies::ENTRY, $secure);
        if (!ReviewTokens::isEntryValue($entryValue)) {
            $entryValue = ReviewTokens::newEntryValue();
        }

        return $this->responder->page(
            $request,
            'Entry',
            [
                'formToken' => $this->tokens->entryToken($entryValue, $now),
                'messageId' => $messageId,
                'name' => $name,
                'enterPath' => self::BASE_PATH . '/enter',
            ],
            $status,
            [ReviewCookies::header(ReviewCookies::ENTRY, $entryValue, ReviewTokens::ENTRY_LIFETIME, $secure)],
            $retryAfter,
        );
    }

    private function enter(ServerRequestInterface $request, bool $secure, int $now): ResponseInterface
    {
        $body = $this->body($request);
        $entryValue = ReviewCookies::read($request->getCookieParams(), ReviewCookies::ENTRY, $secure);
        if (!$this->sameOrigin($request) || !$this->tokens->isValidEntryToken($this->string($body, 'form_token'), $entryValue, $now)) {
            return $this->entryPage($request, $secure, $now, 400, 'entry_form_expired');
        }

        $outcome = $this->authenticator->enter(
            trim($this->string($body, 'token', false)),
            $this->string($body, 'passphrase', false),
            $this->string($body, 'name'),
            $this->sourceAddress($request),
            $now,
        );

        return match ($outcome->status) {
            EnterStatus::Opened => $this->responder->redirect(
                self::BASE_PATH . '/draft',
                [ReviewCookies::header(ReviewCookies::SESSION, $outcome->sessionId, $outcome->sessionLifetime, $secure)],
            ),
            EnterStatus::Limited => $this->entryPage($request, $secure, $now, 429, 'entry_limited', $outcome->retryAfter),
            EnterStatus::Invalid => $this->entryPage($request, $secure, $now, 404, 'entry_invalid', 0, mb_substr($this->string($body, 'name'), 0, ReviewInput::NAME_MAX)),
        };
    }

    /**
     * @param array{action: string, section: string, field: string, text: string, baseText: string}|null $retained what the reviewer typed and the text they saw, shown again after a refusal
     */
    private function draftPage(ServerRequestInterface $request, bool $secure, int $now, int $status = 200, string $messageId = '', int $retryAfter = 0, ?array $retained = null): ResponseInterface
    {
        $context = $this->context($request, $secure, $now);
        if ($context === null) {
            return $this->entryPage($request, $secure, $now, 404, 'entry_invalid');
        }

        $query = $request->getQueryParams();
        $flash = is_string($query['done'] ?? null) ? $query['done'] : '';
        if ($messageId === '' && in_array($flash, ['comment', 'suggest', 'edit'], true)) {
            $messageId = 'done_' . $flash;
        }

        return $this->responder->page(
            $request,
            'Draft',
            $this->draftVariables($context, $messageId, $status >= 400, $retained),
            $status,
            [],
            $retryAfter,
        );
    }

    /**
     * @param 'comment'|'suggest'|'edit' $action
     */
    private function write(ServerRequestInterface $request, bool $secure, int $now, string $action): ResponseInterface
    {
        $context = $this->context($request, $secure, $now);
        if ($context === null) {
            return $this->entryPage($request, $secure, $now, 404, 'entry_invalid');
        }
        $body = $this->body($request);
        if (!$this->sameOrigin($request) || !$this->tokens->isValidSessionToken($context->sessionId, $this->string($body, 'form_token'))) {
            return $this->draftPage($request, $secure, $now, 400, 'error_csrf');
        }
        $section = $this->string($body, 'section');
        $field = $this->string($body, 'field');
        $retained = [
            'action' => $action,
            'section' => $section,
            'field' => $field,
            'text' => $this->string($body, 'text', false),
            // The text the reviewer saw, not the refreshed one: a conflict stays a
            // conflict until they reload the page.
            'baseText' => $this->string($body, 'base_text'),
        ];
        $wait = $this->limits->consumeWrite(ReviewTokens::sessionHash($context->sessionId), $now)
            ?? $this->limits->consumeLinkWrite($context->link->tokenPrefix, $now);
        if ($wait !== null) {
            return $this->draftPage($request, $secure, $now, 429, 'error_limited', $wait, $retained);
        }

        try {
            match ($action) {
                'comment' => $this->reviewService->comment($context, $section, $field, $this->string($body, 'text', false), $now),
                'suggest' => $this->reviewService->suggest($context, $section, $field, $this->string($body, 'text', false), $now),
                'edit' => $this->reviewService->edit($context, $section, $field, $this->string($body, 'base_text', false), $this->string($body, 'text', false), $now),
            };
        } catch (ReviewRefusedException $e) {
            [$status, $message] = match ($e->reason) {
                ReviewRefusedException::FORBIDDEN => [403, 'error_forbidden'],
                ReviewRefusedException::CONFLICT => [409, 'error_conflict'],
                ReviewRefusedException::LIMIT => [429, 'error_limited'],
                default => [400, 'error_invalid'],
            };

            return $this->draftPage($request, $secure, $now, $status, $message, 0, $retained);
        }

        return $this->responder->redirect(self::BASE_PATH . '/draft?done=' . $action . '#section-' . rawurlencode($section));
    }

    private function leave(ServerRequestInterface $request, bool $secure, int $now): ResponseInterface
    {
        $context = $this->context($request, $secure, $now);
        $body = $this->body($request);
        $cookies = [];
        // Only the owner of the session, through its own form, ends it and
        // clears the cookie: a third party's cross-site form does neither.
        if ($context !== null && $this->sameOrigin($request) && $this->tokens->isValidSessionToken($context->sessionId, $this->string($body, 'form_token'))) {
            $this->authenticator->leave($context->sessionId);
            $cookies = [ReviewCookies::header(ReviewCookies::SESSION, '', 0, $secure)];
        }

        return $this->responder->redirect(self::BASE_PATH, $cookies);
    }

    private function context(ServerRequestInterface $request, bool $secure, int $now): ?ReviewContext
    {
        return $this->authenticator->resolve(ReviewCookies::read($request->getCookieParams(), ReviewCookies::SESSION, $secure), $now);
    }

    /**
     * @param array{action: string, section: string, field: string, text: string, baseText: string}|null $retained
     * @return array<string, mixed>
     */
    private function draftVariables(ReviewContext $context, string $messageId, bool $isError, ?array $retained = null): array
    {
        $draft = $context->draft;
        $open = $draft->status === DraftStatus::Draft;
        $bySection = [];
        foreach ($this->comments->findByDraft($draft->uid) as $comment) {
            $bySection[$comment->sectionId][] = $this->commentView($comment);
        }

        $sections = [];
        foreach ($draft->sections as $index => $section) {
            $id = is_string($section['id'] ?? null) ? $section['id'] : '';
            $fields = [];
            foreach (ReviewService::FIELDS as $field) {
                $text = ReviewService::fieldText($section, $field);
                $kept = static fn(string $action): ?array => $retained !== null && $retained['action'] === $action && $retained['section'] === $id && $retained['field'] === $field ? $retained : null;
                $typed = static fn(string $action): string => $kept($action)['text'] ?? $text;
                $fields[] = [
                    'name' => $field,
                    'text' => $text,
                    'lines' => $this->lines($text),
                    'empty' => $text === '',
                    'suggestText' => $typed('suggest'),
                    'editText' => $typed('edit'),
                    'baseText' => $kept('edit')['baseText'] ?? $text,
                    'maxLength' => $field === 'bodytext' ? ReviewInput::SUGGESTION_MAX : ReviewService::SHORT_FIELD_MAX,
                ];
            }
            $sections[] = [
                'id' => $id,
                'number' => $index + 1,
                'name' => is_string($section['section'] ?? null) ? $section['section'] : '',
                'fields' => $fields,
                'commentText' => $retained !== null && $retained['action'] === 'comment' && $retained['section'] === $id ? $retained['text'] : '',
                'comments' => $bySection[$id] ?? [],
            ];
        }

        return [
            'title' => $draft->title,
            'permission' => $context->permission()->value,
            'canComment' => $open && $context->may(LinkPermission::Comment),
            'canEdit' => $open && $context->may(LinkPermission::Edit),
            'draftStatus' => $draft->status->value,
            'reviewerName' => $context->session->reviewerName,
            'expires' => gmdate('Y-m-d H:i', $context->link->expires) . ' UTC',
            'sections' => $sections,
            'formToken' => $this->tokens->sessionToken($context->sessionId),
            'messageId' => $messageId,
            'isError' => $isError,
            'commentPath' => self::BASE_PATH . '/comment',
            'suggestPath' => self::BASE_PATH . '/suggest',
            'editPath' => self::BASE_PATH . '/edit',
            'leavePath' => self::BASE_PATH . '/leave',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commentView(ReviewComment $comment): array
    {
        return [
            'author' => $comment->authorName,
            'fromEditor' => !$comment->isFromReviewer(),
            'kind' => $comment->kind->value,
            'isComment' => $comment->kind === CommentKind::Comment,
            'field' => $comment->field,
            'status' => $comment->status->value,
            'bodyLines' => $this->lines($comment->kind === CommentKind::Comment ? $comment->body : $comment->suggestedText),
            'when' => gmdate('Y-m-d H:i', $comment->crdate) . ' UTC',
        ];
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map(trim(...), explode("\n", $text)), static fn(string $line): bool => $line !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        /** @var array<string, mixed> $result */
        $result = is_array($parsed) ? $parsed : [];

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function string(array $body, string $key, bool $clean = true): string
    {
        $value = $body[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return $clean ? ReviewInput::text($value) : $value;
    }

    /**
     * A POST must come from this very site: `Sec-Fetch-Site` of a browser
     * that sends it says same-origin (or none, for a typed address), and a
     * present `Origin` equals the request's own (the opaque `null` counts as
     * absent, see below). Neither is trusted alone:
     * the form tokens are what protect a client that sends neither.
     */
    private function sameOrigin(ServerRequestInterface $request): bool
    {
        $site = strtolower($request->getHeaderLine('Sec-Fetch-Site'));
        if ($site !== '' && $site !== 'same-origin' && $site !== 'none') {
            return false;
        }
        $origin = $request->getHeaderLine('Origin');
        // The pages say `Referrer-Policy: no-referrer`, so a browser sends the
        // serialised opaque origin `null` with every form of this very site. Where
        // the browser sends `Sec-Fetch-Site`, the check above has already refused
        // another site, a sandboxed one included.
        if ($origin === '' || strtolower($origin) === 'null') {
            return true;
        }
        // The scheme and host as TYPO3 sees them, behind a reverse proxy too.
        return strtolower($origin) === strtolower($this->normalizedParams($request)->getRequestHost());
    }

    private function sourceAddress(ServerRequestInterface $request): string
    {
        return $this->normalizedParams($request)->getRemoteAddress();
    }

    private function normalizedParams(ServerRequestInterface $request): NormalizedParams
    {
        $params = $request->getAttribute('normalizedParams');

        return $params instanceof NormalizedParams ? $params : NormalizedParams::createFromRequest($request);
    }
}
