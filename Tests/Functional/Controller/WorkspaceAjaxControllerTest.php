<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Controller;

use Netresearch\NrLandingpage\Controller\Backend\WorkspaceAjaxController;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareToken;
use Netresearch\NrLandingpage\Tests\Functional\Service\Workspace\AbstractWorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;

#[CoversClass(WorkspaceAjaxController::class)]
final class WorkspaceAjaxControllerTest extends AbstractWorkspaceTestCase
{
    private WorkspaceAjaxController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $controller = $this->get(WorkspaceAjaxController::class);
        self::assertInstanceOf(WorkspaceAjaxController::class, $controller);
        $this->controller = $controller;
    }

    #[Test]
    public function theOwnerCreatesALinkAndTheTokenIsInThatResponseOnly(): void
    {
        $uid = $this->makeDraft(2);
        $this->actAs(2);

        $response = $this->controller->createLinkAction($this->post(['draft' => $uid, 'permission' => 'comment', 'lifetimeDays' => 7, 'label' => 'Agency', 'passphrase' => '', 'maxUses' => 0]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertTrue($body['success']);
        $token = $body['token'];
        self::assertIsString($token);
        self::assertNotNull(ShareToken::prefixOf($token));
        self::assertSame('https://typo3-testing.local/nr-landingpage/review#' . $token, $body['url']);
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));

        // The same token is nowhere in the database.
        $links = $this->get(ShareLinkRepository::class);
        self::assertInstanceOf(ShareLinkRepository::class, $links);
        foreach ($links->findByDraft($uid) as $link) {
            self::assertNotSame($token, $link->tokenHash);
            self::assertStringNotContainsString(substr($token, -43), json_encode($link, JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function aConfiguredPublicBaseUrlIsUsedForTheLink(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_landingpage']['workspace']['publicBaseUrl'] = 'https://demo.example.org/';
        $uid = $this->makeDraft(2);
        $this->actAs(2);

        $body = $this->json($this->controller->createLinkAction($this->post(['draft' => $uid, 'permission' => 'view', 'lifetimeDays' => 7])));

        self::assertIsString($body['url']);
        self::assertStringStartsWith('https://demo.example.org/nr-landingpage/review#nrws_', $body['url']);
    }

    #[Test]
    public function aGroupMemberCannotCreateOrRevokeLinksOfSomeoneElsesDraft(): void
    {
        $uid = $this->makeDraft(2, [1]);
        $this->actAs(3);

        $create = $this->controller->createLinkAction($this->post(['draft' => $uid, 'permission' => 'view', 'lifetimeDays' => 7]));
        $revoke = $this->controller->revokeLinkAction($this->post(['draft' => $uid, 'link' => 1]));

        self::assertSame(404, $create->getStatusCode());
        self::assertSame(404, $revoke->getStatusCode());
        $links = $this->get(ShareLinkRepository::class);
        self::assertInstanceOf(ShareLinkRepository::class, $links);
        self::assertSame([], $links->findByDraft($uid));
    }

    #[Test]
    public function aDraftTheUserMayNotSeeAnswersLikeOneThatDoesNotExist(): void
    {
        $uid = $this->makeDraft(2);
        $this->actAs(4);

        $hidden = $this->controller->createLinkAction($this->post(['draft' => $uid, 'permission' => 'view', 'lifetimeDays' => 7]));
        $missing = $this->controller->createLinkAction($this->post(['draft' => 99999, 'permission' => 'view', 'lifetimeDays' => 7]));

        self::assertSame(404, $hidden->getStatusCode());
        self::assertSame((string) $missing->getBody(), (string) $hidden->getBody());
        self::assertSame($missing->getStatusCode(), $hidden->getStatusCode());
    }

    #[Test]
    public function withoutTheWorkspaceModuleEvenTheOwnerIsRefused(): void
    {
        $uid = $this->makeDraft(2);
        $this->actAs(2, false);

        $response = $this->controller->createLinkAction($this->post(['draft' => $uid, 'permission' => 'view', 'lifetimeDays' => 7]));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('refusedInputs')]
    public function inputsOutsideTheBoundsAreRefusedWithoutStoringALink(array $input): void
    {
        $uid = $this->makeDraft(2);
        $this->actAs(2);

        $response = $this->controller->createLinkAction($this->post(['draft' => $uid] + $input));

        self::assertSame(400, $response->getStatusCode());
        $links = $this->get(ShareLinkRepository::class);
        self::assertInstanceOf(ShareLinkRepository::class, $links);
        self::assertSame([], $links->findByDraft($uid));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedInputs(): iterable
    {
        yield 'no expiry' => [['permission' => 'view', 'lifetimeDays' => 0]];
        yield 'expiry above the maximum' => [['permission' => 'view', 'lifetimeDays' => 31]];
        yield 'unknown permission' => [['permission' => 'approve', 'lifetimeDays' => 7]];
        yield 'no permission' => [['lifetimeDays' => 7]];
        yield 'short passphrase' => [['permission' => 'view', 'lifetimeDays' => 7, 'passphrase' => 'short']];
        yield 'huge usage limit' => [['permission' => 'view', 'lifetimeDays' => 7, 'maxUses' => 100000]];
    }

    #[Test]
    public function noLinkIsCreatedWhileTheReviewPageIsSwitchedOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_landingpage']['workspace']['enabled'] = '0';
        $uid = $this->makeDraft(2);
        $this->actAs(2);

        $response = $this->controller->createLinkAction($this->post(['draft' => $uid, 'permission' => 'view', 'lifetimeDays' => 7]));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('switched off', (string) $response->getBody());
        $links = $this->get(ShareLinkRepository::class);
        self::assertInstanceOf(ShareLinkRepository::class, $links);
        self::assertSame([], $links->findByDraft($uid));
    }

    #[Test]
    public function aLinkIsNotBuiltForAnOriginTheReviewPageWouldRefuse(): void
    {
        $uid = $this->makeDraft(2);
        $this->actAs(2);
        // The plain HTTP origin is the point of this test: the page refuses to build a link for it.
        $request = (new ServerRequest('http://typo3-testing.local/typo3/ajax/nr-landingpage/workspace/create-link', 'POST')) // NOSONAR
            ->withParsedBody(['draft' => $uid, 'permission' => 'view', 'lifetimeDays' => 7]);

        $refused = $this->controller->createLinkAction($request);
        self::assertSame(400, $refused->getStatusCode());
        self::assertStringContainsString('HTTPS', (string) $refused->getBody());

        // With the public origin configured the same request is fine.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_landingpage']['workspace']['publicBaseUrl'] = 'https://demo.example.org';
        self::assertSame(200, $this->controller->createLinkAction($request)->getStatusCode());
    }

    #[Test]
    public function theOwnerRevokesALink(): void
    {
        $uid = $this->makeDraft(2);
        $this->actAs(2);
        $body = $this->json($this->controller->createLinkAction($this->post(['draft' => $uid, 'permission' => 'view', 'lifetimeDays' => 7])));
        $linkUid = $body['uid'];

        $response = $this->controller->revokeLinkAction($this->post(['draft' => $uid, 'link' => $linkUid]));

        self::assertSame(200, $response->getStatusCode());
        $links = $this->get(ShareLinkRepository::class);
        self::assertInstanceOf(ShareLinkRepository::class, $links);
        self::assertTrue($links->findByUid((int) $linkUid)?->isRevoked());
        self::assertSame(400, $this->controller->revokeLinkAction($this->post(['draft' => $uid, 'link' => $linkUid]))->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/ajax/nr-landingpage/workspace/create-link', 'POST'))
            ->withParsedBody($body);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }
}
