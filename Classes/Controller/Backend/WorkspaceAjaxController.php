<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Controller\Backend;

use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Service\BackendAccessGuard;
use Netresearch\NrLandingpage\Service\ImageNotReadableException;
use Netresearch\NrLandingpage\Service\TemplateService;
use Netresearch\NrLandingpage\Service\Workspace\ApprovalService;
use Netresearch\NrLandingpage\Service\Workspace\DraftAccessGuard;
use Netresearch\NrLandingpage\Service\Workspace\DraftService;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkService;
use Netresearch\NrLandingpage\Service\Workspace\SuggestionService;
use Netresearch\NrLandingpage\Service\Workspace\WorkspaceRefusedException;
use Netresearch\NrLandingpage\Service\Workspace\WorkspaceSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * The state-changing actions of the content workspace (ADR-0001): save a
 * draft from the wizard, create a share link, revoke one.
 *
 * Every route is a backend route the core protects with its route token; each
 * action checks again who the user is and what they may do with the draft.
 * A draft the user may not see is answered as not found.
 */
final class WorkspaceAjaxController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** The path of the review page below the public base URL. */
    public const REVIEW_PATH = '/nr-landingpage/review';

    public function __construct(
        private readonly BackendAccessGuard $accessGuard,
        private readonly DraftAccessGuard $draftAccess,
        private readonly DraftService $draftService,
        private readonly ShareLinkService $shareLinks,
        private readonly TemplateService $templateService,
        private readonly WorkspaceSettings $settings,
        private readonly ApprovalService $approval,
        private readonly SuggestionService $suggestions,
    ) {}

    /**
     * Save what the wizard holds as a draft, before the page exists.
     */
    public function saveDraftAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->accessGuard->mayUseWizard() || !$this->accessGuard->mayUseWorkspace()) {
            return $this->forbidden();
        }

        try {
            $body = $this->body($request);
            $template = $this->templateService->loadByUid($this->int($body, 'templateUid'));
            if ($template === null) {
                return $this->failure('Template not found', 400);
            }
            $parentPageId = $this->int($body, 'parentPageId');
            if ($parentPageId > 0 && !$this->accessGuard->mayReadPage($parentPageId)) {
                return $this->forbidden();
            }

            $uid = $this->draftService->create(
                $template,
                $this->string($body, 'title'),
                $parentPageId,
                $this->array($body, 'briefingAnswers'),
                $this->stringMap($body, 'pageFields'),
                $this->array($body, 'contentSections'),
                $this->draftAccess->currentUserUid(),
                time(),
            );

            return new JsonResponse(['success' => true, 'data' => ['draftUid' => $uid]]);
        } catch (WorkspaceRefusedException $e) {
            return $this->failure($e->getMessage(), 400);
        } catch (ImageNotReadableException) {
            return $this->forbidden();
        } catch (Throwable $e) {
            // The class and the code only: the message can carry the content.
            $this->logger?->error('Saving a workspace draft failed', ['exception' => $e::class, 'code' => $e->getCode()]);

            return $this->failure('The draft could not be saved.', 500);
        }
    }

    /**
     * Create a share link. The token is in this response and nowhere else.
     */
    public function createLinkAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $draft = $this->draftAccess->load($this->int($body, 'draft'));
        if ($draft === null || !$this->draftAccess->mayShare($draft)) {
            return $this->forbidden();
        }
        if (!$this->settings->isEnabled()) {
            return $this->failure('The review page is switched off (workspace.enabled), so a share link would lead nowhere.', 400);
        }
        $permission = LinkPermission::tryFrom($this->string($body, 'permission'));
        if ($permission === null) {
            return $this->failure('Unknown permission level.', 400);
        }
        $baseUrl = $this->baseUrl($request);
        if (!str_starts_with($baseUrl, 'https://') && !$this->settings->allowInsecureHttp()) {
            return $this->failure('The review page answers HTTPS only, and this backend request is not HTTPS. Set the public base URL (workspace.publicBaseUrl) to the https origin.', 400);
        }

        try {
            $issued = $this->shareLinks->issue(
                $draft,
                $permission,
                $this->int($body, 'lifetimeDays'),
                $this->string($body, 'label'),
                is_string($body['passphrase'] ?? null) ? $body['passphrase'] : '',
                $this->int($body, 'maxUses'),
                $this->draftAccess->currentUserUid(),
                time(),
            );
        } catch (WorkspaceRefusedException $e) {
            return $this->failure($e->getMessage(), 400);
        }

        return new JsonResponse(
            [
                'success' => true,
                'uid' => $issued['uid'],
                'token' => $issued['token'],
                'url' => $baseUrl . self::REVIEW_PATH . '#' . $issued['token'],
                'expires' => $issued['expires'],
            ],
            200,
            ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'],
        );
    }

    public function revokeLinkAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $draft = $this->draftAccess->load($this->int($body, 'draft'));
        if ($draft === null || !$this->draftAccess->mayShare($draft)) {
            return $this->forbidden();
        }

        try {
            $this->shareLinks->revoke($draft, $this->int($body, 'link'), $this->draftAccess->currentUserUid(), time());
        } catch (WorkspaceRefusedException $e) {
            return $this->failure($e->getMessage(), 400);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    private function array(array $body, string $key): array
    {
        $value = $body[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function stringMap(array $body, string $key): array
    {
        $map = [];
        foreach ($this->array($body, $key) as $name => $value) {
            if (is_string($value)) {
                $map[(string) $name] = $value;
            }
        }

        return $map;
    }

    /**
     * Approve the draft at the version the editor looked at, below the page
     * the editor chose, and create the page.
     */
    public function approveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);

        return $this->attempt(fn(): array => [
            'pageUid' => $this->approval->approve($this->int($body, 'draft'), $this->int($body, 'version'), $this->int($body, 'parentPageUid'), time()),
        ]);
    }

    /**
     * Take back an approval that never got its page.
     */
    public function resetApprovalAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);

        return $this->attempt(function () use ($body): array {
            $this->approval->reset($this->int($body, 'draft'), time());

            return [];
        });
    }

    public function acceptSuggestionAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);

        return $this->attempt(function () use ($body): array {
            $this->suggestions->accept($this->int($body, 'draft'), $this->int($body, 'comment'), time());

            return [];
        });
    }

    public function rejectSuggestionAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);

        return $this->attempt(function () use ($body): array {
            $this->suggestions->reject($this->int($body, 'draft'), $this->int($body, 'comment'), time());

            return [];
        });
    }

    /**
     * Run an action that may be refused: the refusal becomes the status that
     * says what kind it was, with a message safe to show.
     *
     * @param callable(): array<string, mixed> $action
     */
    private function attempt(callable $action): ResponseInterface
    {
        try {
            return new JsonResponse(['success' => true] + $action());
        } catch (WorkspaceRefusedException $e) {
            return $this->failure($e->getMessage(), match ($e->reason) {
                WorkspaceRefusedException::NOT_FOUND => 404,
                WorkspaceRefusedException::CONFLICT => 409,
                WorkspaceRefusedException::FAILED => 500,
                default => 400,
            });
        }
    }

    private function baseUrl(ServerRequestInterface $request): string
    {
        $configured = $this->settings->publicBaseUrl();
        if ($configured !== '') {
            return $configured;
        }
        $uri = $request->getUri();

        return $uri->getScheme() . '://' . $uri->getAuthority();
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            /** @var array<string, mixed> $parsed */
            return $parsed;
        }
        $decoded = json_decode((string) $request->getBody(), true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function int(array $body, string $key): int
    {
        $value = $body[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function string(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function forbidden(): ResponseInterface
    {
        // Not found and not allowed look the same: the answer does not tell a
        // user which drafts exist.
        return new JsonResponse(['success' => false, 'error' => 'Not found'], 404);
    }

    private function failure(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'error' => $message], $status);
    }
}
