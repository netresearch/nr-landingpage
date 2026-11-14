<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace\Review;

use Netresearch\NrLandingpage\Domain\Model\Workspace\AuditAction;
use Netresearch\NrLandingpage\Service\Workspace\AuditRepository;
use Netresearch\NrLandingpage\Service\Workspace\DraftRepository;
use Netresearch\NrLandingpage\Service\Workspace\SessionRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareLinkRepository;
use Netresearch\NrLandingpage\Service\Workspace\ShareToken;
use SensitiveParameter;

/**
 * Opens and resolves review sessions (ADR-0001, section 5).
 *
 * `enter()` is the only place a token is looked at. It answers every refusal
 * the same way (`EnterOutcome::invalid()`), whichever check failed.
 */
class ReviewAuthenticator
{
    /** A review session never lasts longer than this, whatever its link allows. */
    public const SESSION_MAX_LIFETIME = 28800;

    /** A passphrase longer than this (bytes) never reaches the hasher; it counts as a wrong one. */
    public const PASSPHRASE_MAX_BYTES = 256;

    public function __construct(
        private readonly ShareLinkRepository $links,
        private readonly DraftRepository $drafts,
        private readonly SessionRepository $sessions,
        private readonly AuditRepository $audit,
        private readonly PassphraseChecker $passphrases,
        private readonly ReviewRateLimits $limits,
    ) {}

    /**
     * Try to open a review session.
     *
     * Order: the token is matched first (a SHA-256 lookup, no password hash).
     * A request whose token did not match consumes the per-address request
     * limiter and is refused with a limit once that is exhausted; a request
     * with a matching token never consumes it, so other clients behind the
     * same address cannot lock out its holder. For a passphrase-protected link
     * whose token matched, the link limiter is consumed before the passphrase
     * is looked at, so guessing it is capped (and a correct guess is refused
     * while the limiter is exhausted, without a hash). Every failure then
     * consumes the per-address failure limiter.
     */
    public function enter(
        #[SensitiveParameter]
        string $token,
        #[SensitiveParameter]
        string $passphrase,
        string $reviewerName,
        string $sourceAddress,
        int $now,
    ): EnterOutcome {
        $prefix = ShareToken::prefixOf($token);
        $link = $prefix !== null ? $this->links->findByPrefix($prefix) : null;
        // Always compared, against a constant when there is no row.
        $tokenMatches = ShareToken::matches($link?->tokenHash, $token);
        if (!$tokenMatches) {
            $link = null;
            // Only a request without a matching token is bounded per address, before
            // any password hash runs: a shared address cannot lock out a valid token.
            $wait = $this->limits->consumeRequest($sourceAddress, $now);
            if ($wait !== null) {
                return EnterOutcome::limited($wait);
            }
        }

        if ($link !== null && $link->hasPassphrase()) {
            $wait = $this->limits->consumeLink($link->tokenPrefix, $now);
            if ($wait !== null) {
                return EnterOutcome::limited($wait);
            }
        }

        // One password-hash operation on every path, matched or not. An
        // oversized passphrase is hashed as an empty one and counts as wrong.
        $oversized = strlen($passphrase) > self::PASSPHRASE_MAX_BYTES;
        $passphraseOk = $this->passphrases->matches($link->passphraseHash ?? '', $oversized ? '' : $passphrase) && !$oversized;
        $draft = $link !== null ? $this->drafts->findByUid($link->draftUid) : null;

        $admitted = $link !== null
            && $passphraseOk
            && $draft !== null
            && $link->isUsable($now)
            && $this->links->consumeUse($link->uid, $now);

        if (!$admitted || $link === null || $draft === null) {
            $wait = $this->limits->consumeEnterFailure($sourceAddress, $now);

            return $wait !== null ? EnterOutcome::limited($wait) : EnterOutcome::invalid();
        }

        $lifetime = max(1, min(self::SESSION_MAX_LIFETIME, $link->expires - $now));
        $sessionId = ReviewTokens::newSessionId();
        $this->sessions->deleteExpired($now);
        $this->sessions->insert($link->uid, ReviewTokens::sessionHash($sessionId), ReviewInput::name($reviewerName), $now + $lifetime, $now);
        $this->audit->record($draft->uid, $link->uid, 0, AuditAction::LinkOpened, $now);

        return EnterOutcome::opened($sessionId, $lifetime);
    }

    /**
     * The context of a session id, or null: the same for a malformed id, an
     * unknown one, an ended session, a revoked or expired link and a deleted
     * draft. An open session survives the link's usage limit running out (the
     * limit counts openings), not a revocation or the link's expiry.
     */
    public function resolve(#[SensitiveParameter] string $sessionId, int $now): ?ReviewContext
    {
        if (!ReviewTokens::isSessionId($sessionId)) {
            return null;
        }
        $session = $this->sessions->findByHash(ReviewTokens::sessionHash($sessionId));
        if ($session === null || $session->isExpired($now)) {
            return null;
        }
        $link = $this->links->findByUid($session->linkUid);
        if ($link === null || $link->isRevoked() || $link->isExpired($now)) {
            return null;
        }
        $draft = $this->drafts->findByUid($link->draftUid);
        if ($draft === null) {
            return null;
        }

        return new ReviewContext($session, $link, $draft, $sessionId);
    }

    public function leave(#[SensitiveParameter] string $sessionId): void
    {
        if (ReviewTokens::isSessionId($sessionId)) {
            $this->sessions->delete(ReviewTokens::sessionHash($sessionId));
        }
    }
}
