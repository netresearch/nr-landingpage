<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace\Review;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * The limiters of the review page (ADR-0001, section 5), on TYPO3's
 * caching framework storage like nr-llm's A2A authenticator. Each can be
 * tuned under `$GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter'][<id>]`.
 *
 * Every `consume*()` returns the seconds to wait when the limit is hit, or
 * null when the call may go on.
 */
class ReviewRateLimits
{
    public const ENTER = 'nrlp-review-enter';

    public const LINK = 'nrlp-review-link';

    public const WRITE = 'nrlp-review-write';

    public const LINK_WRITE = 'nrlp-review-link-write';

    public const REQUEST = 'nrlp-review-request';

    private const DEFAULTS = [
        self::ENTER => ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '15 minutes'],
        self::LINK => ['policy' => 'sliding_window', 'limit' => 5, 'interval' => '15 minutes'],
        self::WRITE => ['policy' => 'sliding_window', 'limit' => 30, 'interval' => '10 minutes'],
        self::LINK_WRITE => ['policy' => 'sliding_window', 'limit' => 100, 'interval' => '24 hours'],
        self::REQUEST => ['policy' => 'sliding_window', 'limit' => 60, 'interval' => '15 minutes'],
    ];

    public function __construct(
        private readonly StorageInterface $storage,
    ) {}

    /**
     * Every entry attempt, keyed by the source address, before any password
     * hash runs: a generous bound on the work an address can ask for, far
     * above what recipients behind one address need.
     */
    public function consumeRequest(string $sourceAddress, int $now): ?int
    {
        return $this->consume(self::REQUEST, $sourceAddress, $now);
    }

    /**
     * A failed entry, keyed by the source address.
     */
    public function consumeEnterFailure(string $sourceAddress, int $now): ?int
    {
        return $this->consume(self::ENTER, $sourceAddress, $now);
    }

    /**
     * An entry for a passphrase-protected link whose token matched, keyed by
     * the token prefix. Only a holder of the secret gets here.
     */
    public function consumeLink(string $tokenPrefix, int $now): ?int
    {
        return $this->consume(self::LINK, $tokenPrefix, $now);
    }

    /**
     * A write in a session, keyed by the hash of the session id.
     */
    public function consumeWrite(string $sessionHash, int $now): ?int
    {
        return $this->consume(self::WRITE, $sessionHash, $now);
    }

    /**
     * A write through a link, keyed by the link's token prefix (unique per
     * link and, unlike a uid, never reused). Entering again gives a fresh
     * session but the same link, so the per-session limit alone does not stop
     * one link from filling the draft's entry cap for everyone else.
     */
    public function consumeLinkWrite(string $tokenPrefix, int $now): ?int
    {
        return $this->consume(self::LINK_WRITE, $tokenPrefix, $now);
    }

    private function consume(string $id, string $key, int $now): ?int
    {
        $limit = $this->factory($id)->create($key)->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - $now);
    }

    private function factory(string $id): RateLimiterFactory
    {
        $configuration = ['id' => $id] + self::DEFAULTS[$id];
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $node = is_array($confVars) ? ($confVars['SYS'] ?? null) : null;
        foreach (['rateLimiter', $id] as $segment) {
            $node = is_array($node) ? ($node[$segment] ?? null) : null;
        }
        if (is_array($node)) {
            $configuration = array_replace($configuration, $node);
        }

        return new RateLimiterFactory($configuration, $this->storage);
    }
}
