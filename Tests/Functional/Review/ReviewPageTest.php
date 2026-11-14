<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Review;

use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Middleware\ReviewPageMiddleware;
use Netresearch\NrLandingpage\Service\Workspace\CommentRepository;
use Netresearch\NrLandingpage\Service\Workspace\DraftRepository;
use Netresearch\NrLandingpage\Service\Workspace\SessionRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkService;
use Netresearch\NrLandingpage\Tests\Functional\Service\Workspace\AbstractWorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * The review page through the real frontend middleware stack (ADR-0001,
 * section 5 and 10): no login, one draft per link, the same answer for every
 * refusal, no token in anything it renders.
 */
#[CoversClass(ReviewPageMiddleware::class)]
final class ReviewPageTest extends AbstractWorkspaceTestCase
{
    private const HOST = 'https://typo3-testing.local';

    private const BASE = '/nr-landingpage/review';

    private int $draftUid = 0;

    /** The rate limiters keep their state in a cache that outlives a test: each test has its own address. */
    private string $address = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->address = sprintf('10.%d.%d.%d', random_int(1, 250), random_int(1, 250), random_int(1, 250));
        $this->draftUid = $this->makeDraft(2, [], 'Spring campaign');
    }

    #[Test]
    public function theEntryPageHasTheSecurityHeadersAndTheEntryCookie(): void
    {
        $response = $this->fetch(self::BASE);

        self::assertSame(200, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
        self::assertStringContainsString('<meta name="robots" content="noindex,nofollow">', (string) $response->getBody());
        self::assertStringContainsString('<meta name="referrer" content="no-referrer">', (string) $response->getBody());
        self::assertStringContainsString('name="form_token"', (string) $response->getBody());
        $cookie = $this->cookieHeader($response, '__Host-nrws_entry');
        self::assertStringContainsString('; Path=/; HttpOnly; SameSite=Strict; Secure', $cookie);
        self::assertStringNotContainsString('Domain', $cookie);
        self::assertSame([], $this->cookieHeaders($response, '__Host-nrws_session'));
    }

    #[Test]
    public function everyLabelIsResolvedAndAssetsHaveAbsolutePaths(): void
    {
        [$token] = $this->link(LinkPermission::Edit);
        $session = $this->enter($token);

        foreach ([$this->fetch(self::BASE), $this->fetch(self::BASE . '/draft', $session['cookies'])] as $response) {
            $body = (string) $response->getBody();
            self::assertStringNotContainsString('EXT:', $body, 'an unresolved label reference');
            self::assertStringNotContainsString('locallang_review', $body);
            self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/[^"]+review\.css#', $body);
        }
        self::assertMatchesRegularExpression('#<script src="/[^"]+review-entry\.js" defer>#', (string) $this->fetch(self::BASE)->getBody());
    }

    #[Test]
    public function theLanguageFollowsTheBrowser(): void
    {
        $german = $this->executeFrontendSubRequest((new InternalRequest(self::HOST . self::BASE))->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.5')->withServerParams($this->serverParams(self::HOST, [])));
        $other = $this->executeFrontendSubRequest((new InternalRequest(self::HOST . self::BASE))->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')->withServerParams($this->serverParams(self::HOST, [])));

        self::assertStringContainsString('lang="de"', (string) $german->getBody());
        self::assertStringContainsString('Prüflink öffnen', (string) $german->getBody());
        self::assertStringContainsString('lang="en"', (string) $other->getBody());
        self::assertStringContainsString('Open a review link', (string) $other->getBody());
    }

    #[Test]
    public function thePageLoadsNoThirdPartyResourceAndHasNoInlineScriptOrStyle(): void
    {
        $body = (string) $this->fetch(self::BASE)->getBody();

        self::assertSame(0, preg_match('#(src|href|action)="https?://#i', $body));
        self::assertSame(0, preg_match('#<script(?![^>]*\ssrc=)[^>]*>#i', $body), 'an inline script');
        self::assertSame(0, preg_match('#<style#i', $body));
        self::assertSame(0, preg_match('#\sstyle="#i', $body));
    }

    #[Test]
    public function aValidLinkOpensTheDraftAndTheTokenIsNowhereInWhatComesBack(): void
    {
        [$token] = $this->link(LinkPermission::Comment);

        $session = $this->enter($token, name: 'Jane Doe');

        self::assertSame(303, $session['response']->getStatusCode());
        self::assertSame(self::BASE . '/draft', $session['response']->getHeaderLine('Location'));
        $cookie = $this->cookieHeader($session['response'], '__Host-nrws_session');
        self::assertStringContainsString('; Path=/; HttpOnly; SameSite=Strict; Secure', $cookie);
        self::assertStringNotContainsString('Domain', $cookie);
        $this->assertTokenAbsent($token, $session['response']);

        $page = $this->fetch(self::BASE . '/draft', $session['cookies']);

        self::assertSame(200, $page->getStatusCode());
        $this->assertSecurityHeaders($page);
        $this->assertTokenAbsent($token, $page);
        $body = (string) $page->getBody();
        self::assertStringContainsString('Spring campaign', $body);
        self::assertStringContainsString('Jane Doe', $body);
        self::assertStringNotContainsString('nrws_', $body, 'no token-shaped string at all');
    }

    #[Test]
    public function everyKindOfRefusedEntryLooksTheSame(): void
    {
        [$goodToken] = $this->link(LinkPermission::View);
        [$expired, $expiredUid] = $this->link(LinkPermission::View, lifetimeDays: 1);
        $this->backdate($expiredUid, expires: 1);
        [$revoked, $revokedUid] = $this->link(LinkPermission::View);
        $this->service()->revoke($this->draft(), $revokedUid, 1, time());
        [$protected] = $this->link(LinkPermission::View, passphrase: 'correct horse');
        [$once, $onceUid] = $this->link(LinkPermission::View, maxUses: 1);
        self::assertSame(303, $this->enter($once)['response']->getStatusCode(), 'the one use');
        self::assertNotNull($this->links()->findByUid($onceUid));
        $other = $this->link(LinkPermission::View)[0];
        $sameSecret = 'nrws_' . substr($goodToken, 5, 16) . '_' . substr($other, 5 + 16 + 1);
        $deletedDraft = $this->makeDraft(2, [], 'gone');
        [$ofDeleted] = $this->link(LinkPermission::View, draftUid: $deletedDraft);
        $this->getConnectionPool()->getConnectionForTable(DraftRepository::TABLE)->update(DraftRepository::TABLE, ['deleted' => 1], ['uid' => $deletedDraft]);

        $unknown = 'nrws_0000000000000000_' . str_repeat('A', 43);
        $refusals = [
            'unknown' => $unknown,
            'malformed' => 'not a token',
            'empty' => '',
            'right prefix, wrong secret' => $sameSecret,
            'expired' => $expired,
            'revoked' => $revoked,
            'wrong passphrase' => $protected,
            'usage limit used up' => $once,
            'draft deleted' => $ofDeleted,
        ];

        $reference = null;
        foreach ($refusals as $name => $token) {
            $result = $this->enter($token, passphrase: 'wrong', entryCookie: 'AAAAAAAAAAAAAAAAAAAAAA');
            $response = $result['response'];
            self::assertSame(404, $response->getStatusCode(), $name);
            self::assertSame([], $this->cookieHeaders($response, '__Host-nrws_session'), $name);
            $this->assertSecurityHeaders($response, $name);
            $shape = [
                $response->getStatusCode(),
                $this->normalised((string) $response->getBody()),
                $this->headersWithoutVolatile($response),
                $this->cookieHeaders($response, '__Host-nrws_entry'),
            ];
            $reference ??= $shape;
            self::assertSame($reference, $shape, 'the refusal for "' . $name . '" differs from the others');
            self::assertStringContainsString('not valid or has expired', (string) $response->getBody());
        }
    }

    #[Test]
    public function aSessionWithoutOrWithAForgedCookieGetsTheSameEntryPageNotFound(): void
    {
        $none = $this->fetch(self::BASE . '/draft');
        $forged = $this->fetch(self::BASE . '/draft', ['__Host-nrws_session' => str_repeat('A', 43)]);
        $malformed = $this->fetch(self::BASE . '/draft', ['__Host-nrws_session' => 'x']);

        foreach ([$none, $forged, $malformed] as $response) {
            self::assertSame(404, $response->getStatusCode());
            self::assertStringContainsString('not valid or has expired', (string) $response->getBody());
        }
    }

    #[Test]
    public function aViewLinkCannotCommentSuggestOrEditEvenWithAForgedForm(): void
    {
        [$token] = $this->link(LinkPermission::View);
        $session = $this->enter($token);

        foreach (['comment' => ['text' => 'hi'], 'suggest' => ['field' => 'header', 'text' => 'New'], 'edit' => ['field' => 'header', 'base_text' => 'H', 'text' => 'New']] as $action => $fields) {
            $response = $this->submit(self::BASE . '/' . $action, $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa'] + $fields), $session['cookies']);
            self::assertSame(403, $response->getStatusCode(), $action);
        }
        self::assertSame([], $this->comments()->findByDraft($this->draftUid));
        self::assertSame('H', $this->draft()->sections[0]['header']);
        self::assertStringNotContainsString('name="text"', (string) $this->fetch(self::BASE . '/draft', $session['cookies'])->getBody(), 'no form is rendered');
    }

    #[Test]
    public function aCommentLinkCommentsAndSuggestsButCannotEdit(): void
    {
        [$token] = $this->link(LinkPermission::Comment);
        $session = $this->enter($token, name: 'Jane');
        $version = $this->draft()->version;

        $comment = $this->submit(self::BASE . '/comment', $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa', 'field' => '', 'text' => "First line\nSecond line"]), $session['cookies']);
        $suggest = $this->submit(self::BASE . '/suggest', $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa', 'field' => 'header', 'text' => 'A better heading']), $session['cookies']);
        $edit = $this->submit(self::BASE . '/edit', $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa', 'field' => 'header', 'base_text' => 'H', 'text' => 'Hacked']), $session['cookies']);

        self::assertSame(303, $comment->getStatusCode());
        self::assertSame(303, $suggest->getStatusCode());
        self::assertSame(403, $edit->getStatusCode());
        $rows = $this->comments()->findByDraft($this->draftUid);
        self::assertCount(2, $rows);
        self::assertSame('Jane', $rows[0]->authorName);
        self::assertSame("First line\nSecond line", $rows[0]->body);
        self::assertSame('suggestion', $rows[1]->kind->value);
        self::assertSame('H', $rows[1]->baseText);
        self::assertSame('A better heading', $rows[1]->suggestedText);
        self::assertSame('H', $this->draft()->sections[0]['header'], 'a suggestion changes nothing');
        self::assertSame($version, $this->draft()->version);
    }

    #[Test]
    public function anEditLinkChangesTheTextAndEveryChangeIsRecorded(): void
    {
        [$token] = $this->link(LinkPermission::Edit);
        $session = $this->enter($token, name: 'Sam');
        $version = $this->draft()->version;

        $edit = $this->submit(self::BASE . '/edit', $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa', 'field' => 'bodytext', 'base_text' => 'b', 'text' => "New text\n\nSecond paragraph with <b>markup</b>"]), $session['cookies']);

        self::assertSame(303, $edit->getStatusCode());
        $draft = $this->draft();
        self::assertSame($version + 1, $draft->version);
        self::assertSame("<p>New text</p>\n<p>Second paragraph with &lt;b&gt;markup&lt;/b&gt;</p>", $draft->sections[0]['bodytext']);
        $rows = $this->comments()->findByDraft($this->draftUid);
        self::assertCount(1, $rows);
        self::assertSame('edit', $rows[0]->kind->value);
        self::assertSame('applied', $rows[0]->status->value);
        self::assertSame('b', $rows[0]->baseText);
        self::assertSame('Sam', $rows[0]->authorName);
    }

    #[Test]
    public function anEditOfTextThatChangedInTheMeantimeIsRefusedAndWritesNothing(): void
    {
        [$token] = $this->link(LinkPermission::Edit);
        $first = $this->enter($token, name: 'One');
        $second = $this->enter($token, name: 'Two');

        $one = $this->submit(self::BASE . '/edit', $this->withSession($first, ['section' => 'aaaaaaaaaaaaaaaa', 'field' => 'header', 'base_text' => 'H', 'text' => 'By one']), $first['cookies']);
        $two = $this->submit(self::BASE . '/edit', $this->withSession($second, ['section' => 'aaaaaaaaaaaaaaaa', 'field' => 'header', 'base_text' => 'H', 'text' => 'By two']), $second['cookies']);

        self::assertSame(303, $one->getStatusCode());
        self::assertSame(409, $two->getStatusCode());
        self::assertSame('By one', $this->draft()->sections[0]['header']);
        self::assertCount(1, $this->comments()->findByDraft($this->draftUid));
    }

    #[Test]
    public function aLinkOfOneDraftCannotReachASectionOfAnother(): void
    {
        $other = $this->makeDraft(2, [], 'Other draft');
        [$token] = $this->link(LinkPermission::Edit);
        $session = $this->enter($token);
        $otherSection = $this->draft($other)->sections[0]['id'];
        self::assertSame('aaaaaaaaaaaaaaaa', $otherSection, 'both drafts share the section id of the fixture');
        $this->getConnectionPool()->getConnectionForTable(DraftRepository::TABLE)->update(DraftRepository::TABLE, ['sections' => json_encode([['id' => 'bbbbbbbbbbbbbbbb', 'header' => 'Other H', 'bodytext' => '<p>x</p>']], JSON_THROW_ON_ERROR)], ['uid' => $other]);

        $response = $this->submit(self::BASE . '/edit', $this->withSession($session, ['section' => 'bbbbbbbbbbbbbbbb', 'field' => 'header', 'base_text' => 'Other H', 'text' => 'Hijacked']), $session['cookies']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Other H', $this->draft($other)->sections[0]['header']);
        self::assertSame('H', $this->draft()->sections[0]['header']);
        self::assertStringNotContainsString('Other draft', (string) $this->fetch(self::BASE . '/draft', $session['cookies'])->getBody());
    }

    #[Test]
    public function aNameAndTextWithMarkupAreEscapedInTheHtml(): void
    {
        [$token] = $this->link(LinkPermission::Comment);
        $session = $this->enter($token, name: '<img src=x onerror=alert(1)>Eve');
        $this->submit(self::BASE . '/comment', $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa', 'field' => '', 'text' => '<script>alert(2)</script> & "quotes"']), $session['cookies']);

        $body = (string) $this->fetch(self::BASE . '/draft', $session['cookies'])->getBody();

        self::assertStringNotContainsString('<script>alert', $body);
        self::assertStringNotContainsString('<img src=x', $body);
        self::assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt; &amp; &quot;quotes&quot;', $body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;Eve', $body);
    }

    #[Test]
    public function aRevokedOrExpiredLinkEndsItsOpenSession(): void
    {
        [$token, $linkUid] = $this->link(LinkPermission::Comment);
        $session = $this->enter($token);
        self::assertSame(200, $this->fetch(self::BASE . '/draft', $session['cookies'])->getStatusCode());

        $this->service()->revoke($this->draft(), $linkUid, 1, time());

        self::assertSame(404, $this->fetch(self::BASE . '/draft', $session['cookies'])->getStatusCode());
        $write = $this->submit(self::BASE . '/comment', $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa', 'text' => 'late']), $session['cookies']);
        self::assertSame(404, $write->getStatusCode());
        self::assertSame([], $this->comments()->findByDraft($this->draftUid));

        [$second, $secondUid] = $this->link(LinkPermission::View);
        $open = $this->enter($second);
        $this->backdate($secondUid, expires: 1);
        self::assertSame(404, $this->fetch(self::BASE . '/draft', $open['cookies'])->getStatusCode());
    }

    #[Test]
    public function anApprovedDraftCanBeReadButTakesNoWrite(): void
    {
        [$token] = $this->link(LinkPermission::Edit);
        $session = $this->enter($token);
        $this->getConnectionPool()->getConnectionForTable(DraftRepository::TABLE)->update(DraftRepository::TABLE, ['status' => 'approved'], ['uid' => $this->draftUid]);

        $page = $this->fetch(self::BASE . '/draft', $session['cookies']);
        $edit = $this->submit(self::BASE . '/edit', $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa', 'field' => 'header', 'base_text' => 'H', 'text' => 'Late']), $session['cookies']);
        $comment = $this->submit(self::BASE . '/comment', $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa', 'text' => 'Late']), $session['cookies']);

        self::assertSame(200, $page->getStatusCode());
        self::assertStringNotContainsString('name="text"', (string) $page->getBody());
        self::assertSame(409, $edit->getStatusCode());
        self::assertSame(409, $comment->getStatusCode());
        self::assertSame('H', $this->draft()->sections[0]['header']);
    }

    #[Test]
    public function leavingEndsTheSessionAndDeletesTheCookie(): void
    {
        [$token] = $this->link(LinkPermission::View);
        $session = $this->enter($token);

        $response = $this->submit(self::BASE . '/leave', $this->withSession($session, []), $session['cookies']);

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('Max-Age=0', $this->cookieHeader($response, '__Host-nrws_session'));
        self::assertSame(404, $this->fetch(self::BASE . '/draft', $session['cookies'])->getStatusCode());
    }

    #[Test]
    public function aFormWithoutTheRightTokenIsRefused(): void
    {
        [$token] = $this->link(LinkPermission::Comment);
        $entry = $this->fetch(self::BASE);
        $entryCookie = $this->cookieValue($entry, '__Host-nrws_entry');
        $formToken = $this->formToken($entry);

        // The entry form: missing, empty and foreign tokens, and another browser's cookie.
        $cases = [
            'missing token' => [['token' => $token, 'name' => 'x'], ['__Host-nrws_entry' => $entryCookie]],
            'empty token' => [['token' => $token, 'form_token' => ''], ['__Host-nrws_entry' => $entryCookie]],
            'no entry cookie' => [['token' => $token, 'form_token' => $formToken], []],
            'another entry cookie' => [['token' => $token, 'form_token' => $formToken], ['__Host-nrws_entry' => 'BBBBBBBBBBBBBBBBBBBBBB']],
        ];
        foreach ($cases as $name => [$body, $cookies]) {
            $response = $this->submit(self::BASE . '/enter', $body, $cookies);
            self::assertSame(400, $response->getStatusCode(), $name);
            self::assertSame([], $this->cookieHeaders($response, '__Host-nrws_session'), $name);
        }
        self::assertSame(0, $this->links()->findByDraft($this->draftUid)[0]->useCount, 'no refused form used the link up');

        // The forms of a session: no token, and the token of another session.
        $mine = $this->enter($token);
        $theirs = $this->enter($token);
        $body = ['section' => 'aaaaaaaaaaaaaaaa', 'field' => '', 'text' => 'hello'];
        self::assertSame(400, $this->submit(self::BASE . '/comment', $body, $mine['cookies'])->getStatusCode());
        self::assertSame(400, $this->submit(self::BASE . '/comment', $body + ['form_token' => $theirs['formToken']], $mine['cookies'])->getStatusCode());
        self::assertSame([], $this->comments()->findByDraft($this->draftUid));
    }

    #[Test]
    public function aCrossSiteOrForeignOriginPostIsRefused(): void
    {
        [$token] = $this->link(LinkPermission::Comment);
        $entry = $this->fetch(self::BASE);
        $good = ['token' => $token, 'form_token' => $this->formToken($entry)];
        $cookies = ['__Host-nrws_entry' => $this->cookieValue($entry, '__Host-nrws_entry')];

        $crossSite = $this->submit(self::BASE . '/enter', $good, $cookies, ['Sec-Fetch-Site' => 'cross-site']);
        $sameSite = $this->submit(self::BASE . '/enter', $good, $cookies, ['Sec-Fetch-Site' => 'same-site']);
        $foreign = $this->submit(self::BASE . '/enter', $good, $cookies, ['Origin' => 'https://evil.example']);
        $sameOrigin = $this->submit(self::BASE . '/enter', $good, $cookies, ['Sec-Fetch-Site' => 'same-origin', 'Origin' => self::HOST]);

        self::assertSame(400, $crossSite->getStatusCode());
        self::assertSame(400, $sameSite->getStatusCode());
        self::assertSame(400, $foreign->getStatusCode());
        self::assertSame(303, $sameOrigin->getStatusCode(), 'the same page posting to itself works');
    }

    #[Test]
    public function repeatedFailuresFromOneAddressAreRateLimitedAndAValidEntryStillPasses(): void
    {
        $this->setConfig('SYS/rateLimiter/nrlp-review-enter/limit', 2);
        [$token] = $this->link(LinkPermission::View);
        $server = ['REMOTE_ADDR' => '203.0.113.7'];

        $first = $this->enter('not a token', server: $server)['response'];
        $second = $this->enter('not a token', server: $server)['response'];
        $third = $this->enter('not a token', server: $server)['response'];
        $valid = $this->enter($token, server: $server)['response'];
        $otherAddress = $this->enter('not a token', server: ['REMOTE_ADDR' => '203.0.113.8'])['response'];

        self::assertSame(404, $first->getStatusCode());
        self::assertSame(404, $second->getStatusCode());
        self::assertSame(429, $third->getStatusCode());
        self::assertGreaterThan(0, (int) $third->getHeaderLine('Retry-After'));
        $this->assertSecurityHeaders($third);
        self::assertSame(303, $valid->getStatusCode(), 'verify first: a valid link is not locked out by failures');
        self::assertSame(404, $otherAddress->getStatusCode(), 'another address has its own allowance');
    }

    #[Test]
    public function guessingAPassphraseIsCappedPerLinkAndAHammeredPrefixCannotLockOutOthers(): void
    {
        $this->setConfig('SYS/rateLimiter/nrlp-review-link/limit', 2);
        $this->setConfig('SYS/rateLimiter/nrlp-review-enter/limit', 100);
        [$protected, $protectedUid] = $this->link(LinkPermission::View, passphrase: 'correct horse');
        [$free] = $this->link(LinkPermission::View);
        $prefix = substr($protected, 5, 16);

        // A stranger who knows only the prefix never consumes the link limiter.
        for ($i = 0; $i < 5; ++$i) {
            $guess = 'nrws_' . $prefix . '_' . str_repeat('B', 43);
            self::assertSame(404, $this->enter($guess)['response']->getStatusCode());
        }

        // The holder of the whole link is capped: two tries, then 429, also for the right passphrase.
        self::assertSame(404, $this->enter($protected, passphrase: 'wrong 1')['response']->getStatusCode());
        self::assertSame(404, $this->enter($protected, passphrase: 'wrong 2')['response']->getStatusCode());
        self::assertSame(429, $this->enter($protected, passphrase: 'wrong 3')['response']->getStatusCode());
        self::assertSame(429, $this->enter($protected, passphrase: 'correct horse')['response']->getStatusCode());
        self::assertSame(0, $this->links()->findByUid($protectedUid)?->useCount);

        // Other links are not touched.
        self::assertSame(303, $this->enter($free)['response']->getStatusCode());
    }

    #[Test]
    public function anAddressThatAsksForTooMuchWorkIsStoppedBeforeAnyHashEvenWithAValidLink(): void
    {
        $this->setConfig('SYS/rateLimiter/nrlp-review-request/limit', 2);
        [$token] = $this->link(LinkPermission::View);

        self::assertSame(404, $this->enter('not a token')['response']->getStatusCode());
        self::assertSame(404, $this->enter('not a token')['response']->getStatusCode());
        $third = $this->enter($token)['response'];

        self::assertSame(429, $third->getStatusCode());
        self::assertGreaterThan(0, (int) $third->getHeaderLine('Retry-After'));
        self::assertSame([], $this->cookieHeaders($third, '__Host-nrws_session'));
        self::assertSame(0, $this->links()->findByDraft($this->draftUid)[0]->useCount, 'the link was not used');
    }

    #[Test]
    public function aPassphraseOfMoreThan256BytesCountsAsWrong(): void
    {
        [$token] = $this->link(LinkPermission::View);

        self::assertSame(404, $this->enter($token, passphrase: str_repeat('x', 257))['response']->getStatusCode());
        self::assertSame(303, $this->enter($token, passphrase: str_repeat('x', 256))['response']->getStatusCode(), 'a link without passphrase takes any text up to the bound');
    }

    #[Test]
    public function aTokenPastedWithWhiteSpaceIsAccepted(): void
    {
        [$token] = $this->link(LinkPermission::View);

        self::assertSame(303, $this->enter(" \n" . $token . "\r\n ")['response']->getStatusCode());
    }

    #[Test]
    public function aThirdPartysLeaveFormEndsNothingAndClearsNothing(): void
    {
        [$token] = $this->link(LinkPermission::View);
        $session = $this->enter($token);

        $crossSite = $this->submit(self::BASE . '/leave', $this->withSession($session, []), $session['cookies'], ['Sec-Fetch-Site' => 'cross-site']);
        $noToken = $this->submit(self::BASE . '/leave', [], $session['cookies']);

        self::assertSame([], $this->cookieHeaders($crossSite, '__Host-nrws_session'));
        self::assertSame([], $this->cookieHeaders($noToken, '__Host-nrws_session'));
        self::assertSame(200, $this->fetch(self::BASE . '/draft', $session['cookies'])->getStatusCode(), 'the session is still open');
    }

    #[Test]
    public function textThatIsNotValidUtf8IsRefusedAndNeverDeletesAField(): void
    {
        [$token] = $this->link(LinkPermission::Edit);
        $session = $this->enter($token);

        $edit = $this->submit(self::BASE . '/edit', $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa', 'field' => 'header', 'base_text' => 'H', 'text' => "bad \xC3\x28 bytes"]), $session['cookies']);

        self::assertSame(400, $edit->getStatusCode());
        self::assertSame('H', $this->draft()->sections[0]['header']);
    }

    #[Test]
    public function theFormsMirrorTheServerLimitsAndAKeptTextComesBackAfterARefusal(): void
    {
        [$token] = $this->link(LinkPermission::Edit);
        $session = $this->enter($token);
        $page = (string) $this->fetch(self::BASE . '/draft', $session['cookies'])->getBody();

        self::assertMatchesRegularExpression('/id="s-text-aaaaaaaaaaaaaaaa-header"[^>]*maxlength="255"/', $page);
        self::assertMatchesRegularExpression('/id="e-text-aaaaaaaaaaaaaaaa-subheader"[^>]*maxlength="255"/', $page);
        self::assertMatchesRegularExpression('/id="e-text-aaaaaaaaaaaaaaaa-bodytext"[^>]*maxlength="4000"/', $page);

        $tooLong = str_repeat('Long heading ', 30);
        $refused = $this->submit(self::BASE . '/edit', $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa', 'field' => 'header', 'base_text' => 'H', 'text' => $tooLong]), $session['cookies']);

        self::assertSame(400, $refused->getStatusCode());
        self::assertStringContainsString(trim($tooLong), (string) $refused->getBody(), 'what the reviewer typed is shown again');
        self::assertSame('H', $this->draft()->sections[0]['header']);
    }

    #[Test]
    public function aDraftTakesNoMoreEntriesThanItsLimit(): void
    {
        [$token] = $this->link(LinkPermission::Comment);
        $session = $this->enter($token);
        $comments = $this->comments();
        for ($i = 0; $i < \Netresearch\NrLandingpage\Service\Workspace\Review\ReviewService::MAX_ENTRIES_PER_DRAFT; ++$i) {
            $comments->insert($this->draftUid, 0, 0, 'aaaaaaaaaaaaaaaa', '', \Netresearch\NrLandingpage\Domain\Model\Workspace\CommentKind::Comment, 'x', 'filler', '', '', \Netresearch\NrLandingpage\Domain\Model\Workspace\CommentStatus::Open, self::NOW);
        }

        $response = $this->submit(self::BASE . '/comment', $this->withSession($session, ['section' => 'aaaaaaaaaaaaaaaa', 'field' => '', 'text' => 'one too many']), $session['cookies']);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(\Netresearch\NrLandingpage\Service\Workspace\Review\ReviewService::MAX_ENTRIES_PER_DRAFT, $comments->countByDraft($this->draftUid));
    }

    #[Test]
    public function aPassphraseLinkAdmitsTheRightPassphrase(): void
    {
        [$token] = $this->link(LinkPermission::View, passphrase: 'correct horse');

        self::assertSame(404, $this->enter($token, passphrase: 'wrong horse')['response']->getStatusCode());
        self::assertSame(404, $this->enter($token, passphrase: '')['response']->getStatusCode());
        self::assertSame(303, $this->enter($token, passphrase: 'correct horse')['response']->getStatusCode());
    }

    #[Test]
    public function aUsageLimitOfOneAdmitsOneEntry(): void
    {
        [$token, $uid] = $this->link(LinkPermission::View, maxUses: 1);

        self::assertSame(303, $this->enter($token)['response']->getStatusCode());
        self::assertSame(404, $this->enter($token)['response']->getStatusCode());
        self::assertSame(1, $this->links()->findByUid($uid)?->useCount);
    }

    #[Test]
    public function aSessionIsStoredAsAHashAndOpeningItIsAudited(): void
    {
        [$token, $uid] = $this->link(LinkPermission::View);
        $session = $this->enter($token, name: "  Jane\x00  Doe ");

        $sessionId = $session['cookies']['__Host-nrws_session'];
        $row = $this->sessions()->findByHash(hash('sha256', $sessionId));
        self::assertNotNull($row);
        self::assertSame('Jane Doe', $row->reviewerName);
        self::assertSame($uid, $row->linkUid);
        $dump = json_encode($this->getConnectionPool()->getConnectionForTable(SessionRepository::TABLE)->select(['*'], SessionRepository::TABLE)->fetchAllAssociative(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($sessionId, $dump);
        self::assertStringNotContainsString($token, $dump);
        $actions = array_column($this->audit()->findByDraft($this->draftUid), 'action');
        self::assertContains('link_opened', $actions);
    }

    #[Test]
    public function thePageIsGoneWhenSwitchedOffAndOverPlainHttp(): void
    {
        $this->setConfig('EXTENSIONS/nr_landingpage/workspace/enabled', '0');
        $off = $this->fetch(self::BASE);
        $this->setConfig('EXTENSIONS/nr_landingpage/workspace/enabled', '1');
        $http = $this->fetch(self::BASE, [], 'http://typo3-testing.local');

        foreach ([$off, $http] as $response) {
            self::assertStringNotContainsString('name="form_token"', (string) $response->getBody());
            self::assertSame([], $this->cookieHeaders($response, '__Host-nrws_entry'));
            self::assertSame([], $this->cookieHeaders($response, 'nrws_entry'));
        }
    }

    #[Test]
    public function overPlainHttpTheCookiesHaveNoHostPrefixWhenItIsExplicitlyAllowed(): void
    {
        $this->setConfig('EXTENSIONS/nr_landingpage/workspace/allowInsecureHttp', '1');

        $response = $this->fetch(self::BASE, [], 'http://typo3-testing.local');

        self::assertSame(200, $response->getStatusCode());
        $cookie = $this->cookieHeader($response, 'nrws_entry');
        self::assertStringNotContainsString('Secure', $cookie);
        self::assertStringContainsString('HttpOnly; SameSite=Strict', $cookie);
    }

    #[Test]
    public function otherPathsAndMethodsBelowThePrefixAnswerLikeTheEntryNotFound(): void
    {
        foreach ([self::BASE . '/nothing', self::BASE . '/enter', self::BASE . '/draft/../x'] as $path) {
            $response = $this->fetch($path);
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertStringContainsString('not valid or has expired', (string) $response->getBody(), $path);
        }
        $put = $this->executeFrontendSubRequest((new InternalRequest(self::HOST . self::BASE))->withMethod('PUT'));
        self::assertSame(404, $put->getStatusCode());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array{string, int} the token and the uid of the link
     */
    private function link(LinkPermission $permission, int $lifetimeDays = 7, string $passphrase = '', int $maxUses = 0, ?int $draftUid = null): array
    {
        $draft = $this->draft($draftUid ?? $this->draftUid);
        $issued = $this->service()->issue($draft, $permission, $lifetimeDays, 'test', $passphrase, $maxUses, 2, time());

        return [$issued['token'], $issued['uid']];
    }

    private function backdate(int $linkUid, int $expires): void
    {
        $this->getConnectionPool()->getConnectionForTable(ShareLinkRepository::TABLE)->update(ShareLinkRepository::TABLE, ['expires' => $expires], ['uid' => $linkUid]);
    }

    /**
     * Open the entry page as a browser would, then post the form.
     *
     * @param array<string, string> $server
     * @return array{response: ResponseInterface, cookies: array<string, string>, formToken: string}
     */
    private function enter(string $token, string $passphrase = '', string $name = '', ?string $entryCookie = null, array $server = []): array
    {
        $page = $this->fetch(self::BASE, [], self::HOST, $server);
        $entry = $entryCookie ?? $this->cookieValue($page, '__Host-nrws_entry');
        // A made-up entry cookie gets a form token that fits it, as its own browser would hold.
        $formToken = $this->formToken($entryCookie === null ? $page : $this->fetch(self::BASE, ['__Host-nrws_entry' => $entryCookie], self::HOST, $server));
        $response = $this->submit(
            self::BASE . '/enter',
            ['token' => $token, 'passphrase' => $passphrase, 'name' => $name, 'form_token' => $formToken],
            ['__Host-nrws_entry' => $entry],
            [],
            $server,
        );
        $cookies = ['__Host-nrws_entry' => $entry];
        $sessionCookie = $this->cookieValue($response, '__Host-nrws_session', false);
        if ($sessionCookie !== '') {
            $cookies['__Host-nrws_session'] = $sessionCookie;
        }
        $formToken = '';
        if ($sessionCookie !== '') {
            $formToken = $this->formToken($this->fetch(self::BASE . '/draft', $cookies));
        }

        return ['response' => $response, 'cookies' => $cookies, 'formToken' => $formToken];
    }

    /**
     * @param array{response: ResponseInterface, cookies: array<string, string>, formToken: string} $session
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    private function withSession(array $session, array $fields): array
    {
        return $fields + ['form_token' => $session['formToken']];
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $server
     */
    private function fetch(string $path, array $cookies = [], string $host = self::HOST, array $server = []): ResponseInterface
    {
        $request = (new InternalRequest($host . $path))->withCookieParams($cookies)->withServerParams($this->serverParams($host, $server));

        return $this->executeFrontendSubRequest($request);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    private function submit(string $path, array $body, array $cookies = [], array $headers = [], array $server = []): ResponseInterface
    {
        $request = (new InternalRequest(self::HOST . $path))->withMethod('POST')->withParsedBody($body)->withCookieParams($cookies);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $request = $request->withServerParams($this->serverParams(self::HOST, $server));

        return $this->executeFrontendSubRequest($request);
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function serverParams(string $host, array $extra): array
    {
        $name = (string) parse_url($host, PHP_URL_HOST);

        return $extra + [
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => $name,
            'SERVER_NAME' => $name,
            'HTTPS' => str_starts_with($host, 'https') ? 'on' : 'off',
            'REMOTE_ADDR' => $this->address,
        ];
    }

    /**
     * @return list<string>
     */
    private function cookieHeaders(ResponseInterface $response, string $name): array
    {
        return array_values(array_filter(
            $response->getHeader('Set-Cookie'),
            static fn(string $header): bool => str_starts_with($header, $name . '='),
        ));
    }

    private function cookieHeader(ResponseInterface $response, string $name): string
    {
        $headers = $this->cookieHeaders($response, $name);
        self::assertCount(1, $headers, 'Set-Cookie for ' . $name . ' in: ' . implode(' | ', $response->getHeader('Set-Cookie')));

        return $headers[0];
    }

    private function cookieValue(ResponseInterface $response, string $name, bool $required = true): string
    {
        $headers = $this->cookieHeaders($response, $name);
        if ($headers === []) {
            self::assertFalse($required, 'no Set-Cookie for ' . $name);

            return '';
        }

        return explode(';', substr($headers[0], strlen($name) + 1))[0];
    }

    private function formToken(ResponseInterface $response): string
    {
        self::assertSame(1, preg_match('/name="form_token" value="([^"]+)"/', (string) $response->getBody(), $match), 'a form token in the page');

        return html_entity_decode($match[1]);
    }

    private function assertSecurityHeaders(ResponseInterface $response, string $message = ''): void
    {
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'), $message);
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'), $message);
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'), $message);
        self::assertSame('noindex, nofollow, noarchive', $response->getHeaderLine('X-Robots-Tag'), $message);
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), $message);
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'), $message);
        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("default-src 'none'", $csp, $message);
        self::assertStringContainsString("script-src 'self'", $csp, $message);
        self::assertStringContainsString("form-action 'self'", $csp, $message);
        self::assertStringContainsString("frame-ancestors 'none'", $csp, $message);
        self::assertStringNotContainsString('unsafe-inline', $csp, $message);
    }

    private function assertTokenAbsent(string $token, ResponseInterface $response): void
    {
        $secret = substr($token, -43);
        self::assertStringNotContainsString($secret, (string) $response->getBody());
        self::assertStringNotContainsString($secret, json_encode($response->getHeaders(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(substr($token, 5, 16), (string) $response->getBody());
    }

    private function normalised(string $body): string
    {
        return (string) preg_replace('/name="form_token" value="[^"]*"/', 'name="form_token" value="X"', $body);
    }

    /**
     * @return array<string, array<string>>
     */
    private function headersWithoutVolatile(ResponseInterface $response): array
    {
        $headers = $response->getHeaders();
        unset($headers['Content-Length'], $headers['Date']);
        ksort($headers);

        return $headers;
    }

    private function draft(?int $uid = null): \Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft
    {
        $draft = $this->container(DraftRepository::class)->findByUid($uid ?? $this->draftUid);
        self::assertNotNull($draft);

        return $draft;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function container(string $class): object
    {
        $service = $this->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /** @var list<string> */
    private array $configPaths = [];

    /**
     * Settings for the sub requests: written to LocalConfiguration and to the
     * globals, and removed again after the test (the test instance outlives it).
     */
    private function setConfig(string $path, mixed $value): void
    {
        $manager = $this->container(\TYPO3\CMS\Core\Configuration\ConfigurationManager::class);
        // updateLocalConfiguration() takes keys the defaults do not know, such as
        // the id of a rate limiter; setLocalConfigurationValueByPath() does not.
        $nested = $value;
        foreach (array_reverse(explode('/', $path)) as $segment) {
            $nested = [$segment => $nested];
        }
        $manager->updateLocalConfiguration($nested);
        $this->configPaths[] = $path;
        $node = &$GLOBALS['TYPO3_CONF_VARS'];
        foreach (explode('/', $path) as $segment) {
            $node = &$node[$segment];
        }
        $node = $value;
        unset($node);
    }

    protected function tearDown(): void
    {
        if ($this->configPaths !== []) {
            $manager = $this->container(\TYPO3\CMS\Core\Configuration\ConfigurationManager::class);
            $manager->removeLocalConfigurationKeysByPath($this->configPaths);
            foreach ($this->configPaths as $path) {
                $segments = explode('/', $path);
                $last = array_pop($segments);
                $node = &$GLOBALS['TYPO3_CONF_VARS'];
                foreach ($segments as $segment) {
                    $node = &$node[$segment];
                }
                unset($node[$last]);
                unset($node);
            }
            $this->configPaths = [];
        }
        parent::tearDown();
    }

    private function service(): ShareLinkService
    {
        return $this->container(ShareLinkService::class);
    }

    private function links(): ShareLinkRepository
    {
        return $this->container(ShareLinkRepository::class);
    }

    private function comments(): CommentRepository
    {
        return $this->container(CommentRepository::class);
    }

    private function sessions(): SessionRepository
    {
        return $this->container(SessionRepository::class);
    }

    private function audit(): \Netresearch\NrLandingpage\Service\Workspace\AuditRepository
    {
        return $this->container(\Netresearch\NrLandingpage\Service\Workspace\AuditRepository::class);
    }
}
