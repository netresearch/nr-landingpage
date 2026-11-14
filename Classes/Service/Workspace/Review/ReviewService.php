<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace\Review;

use Netresearch\NrLandingpage\Domain\Model\Workspace\CommentKind;
use Netresearch\NrLandingpage\Domain\Model\Workspace\CommentStatus;
use Netresearch\NrLandingpage\Domain\Model\Workspace\LinkPermission;
use Netresearch\NrLandingpage\Domain\Model\Workspace\WorkspaceDraft;
use Netresearch\NrLandingpage\Service\Workspace\CommentRepository;
use Netresearch\NrLandingpage\Service\Workspace\DraftRepository;
use Netresearch\NrLandingpage\Service\Workspace\PlainText;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * What a reviewer may write through a share link (ADR-0001, sections 6 and 7):
 * comments, suggestions and, with an `edit` link, direct changes of the three
 * text fields of an existing section of the link's own draft. Each method
 * checks the link's level itself; the page only renders what is allowed.
 */
class ReviewService
{
    /** The fields of a section a reviewer can comment on or change. */
    public const FIELDS = ['header', 'subheader', 'bodytext'];

    /** `tt_content.header` and `subheader` are varchar(255). */
    public const SHORT_FIELD_MAX = 255;

    /** The most comment, suggestion and edit rows one draft takes through links. */
    public const MAX_ENTRIES_PER_DRAFT = 500;

    public function __construct(
        private readonly DraftRepository $drafts,
        private readonly CommentRepository $comments,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * The text of a field as a reviewer sees and edits it.
     *
     * @param array<string, mixed> $section
     */
    public static function fieldText(array $section, string $field): string
    {
        $value = is_string($section[$field] ?? null) ? $section[$field] : '';

        return $field === 'bodytext' ? PlainText::fromHtml($value) : ReviewInput::text($value);
    }

    public function comment(ReviewContext $context, string $sectionId, string $field, string $body, int $now): void
    {
        $this->require($context, LinkPermission::Comment);
        $draft = $this->openDraft($context);
        $this->sectionOf($draft, $sectionId);
        $this->assertField($field, true);
        $body = $this->text($body, ReviewInput::COMMENT_MAX);
        $this->assertRoom($draft);

        $this->comments->insert(
            $draft->uid,
            $context->link->uid,
            0,
            $sectionId,
            $field,
            CommentKind::Comment,
            $context->session->reviewerName,
            $body,
            '',
            '',
            CommentStatus::Open,
            $now,
        );
    }

    public function suggest(ReviewContext $context, string $sectionId, string $field, string $text, int $now): void
    {
        $this->require($context, LinkPermission::Comment);
        $draft = $this->openDraft($context);
        $section = $this->sectionOf($draft, $sectionId);
        $this->assertField($field, false);
        $suggested = $this->text($text, ReviewInput::SUGGESTION_MAX, $this->maxFor($field));
        $current = self::fieldText($section, $field);
        if ($suggested === $current) {
            throw new ReviewRefusedException(ReviewRefusedException::INVALID, 'The suggestion does not change the text.');
        }
        $this->assertRoom($draft);

        $this->comments->insert(
            $draft->uid,
            $context->link->uid,
            0,
            $sectionId,
            $field,
            CommentKind::Suggestion,
            $context->session->reviewerName,
            '',
            $current,
            $suggested,
            CommentStatus::Open,
            $now,
        );
    }

    /**
     * Change a field directly. The text the reviewer saw (`$baseText`) must
     * still be the current text, and the write is the one conditional update
     * of the draft, so two reviewers cannot overwrite each other and nothing is
     * written once the draft is approved.
     */
    public function edit(ReviewContext $context, string $sectionId, string $field, string $baseText, string $newText, int $now): void
    {
        $this->require($context, LinkPermission::Edit);
        $this->assertField($field, false);
        $baseText = ReviewInput::text($baseText);
        $newText = $this->text($newText, ReviewInput::SUGGESTION_MAX, $this->maxFor($field));

        $this->connectionPool->getConnectionForTable(DraftRepository::TABLE)->transactional(
            function () use ($context, $sectionId, $field, $baseText, $newText, $now): void {
                // A fresh read inside the transaction: the version below is the one
                // the changed content was read with.
                $draft = $this->openDraft($context);
                $section = $this->sectionOf($draft, $sectionId);
                $current = self::fieldText($section, $field);
                if ($current !== $baseText) {
                    throw new ReviewRefusedException(ReviewRefusedException::CONFLICT, 'The text changed since you opened the page.');
                }
                if ($newText === $current) {
                    throw new ReviewRefusedException(ReviewRefusedException::INVALID, 'The text is unchanged.');
                }
                $this->assertRoom($draft);

                $sections = [];
                foreach ($draft->sections as $candidate) {
                    if (($candidate['id'] ?? null) === $sectionId) {
                        $candidate[$field] = $field === 'bodytext' ? PlainText::toBodytextHtml($newText) : $newText;
                    }
                    $sections[] = $candidate;
                }
                if (!$this->drafts->updateSections($draft->uid, $sections, $draft->version, $now)) {
                    throw new ReviewRefusedException(ReviewRefusedException::CONFLICT, 'The draft changed or is closed.');
                }
                $this->comments->insert(
                    $draft->uid,
                    $context->link->uid,
                    0,
                    $sectionId,
                    $field,
                    CommentKind::Edit,
                    $context->session->reviewerName,
                    '',
                    $current,
                    $newText,
                    CommentStatus::Applied,
                    $now,
                );
            },
        );
    }

    private function require(ReviewContext $context, LinkPermission $needed): void
    {
        if (!$context->may($needed)) {
            throw new ReviewRefusedException(ReviewRefusedException::FORBIDDEN, 'This link does not allow that.');
        }
    }

    /**
     * The draft as stored now; a draft that is not open takes no write.
     */
    private function openDraft(ReviewContext $context): WorkspaceDraft
    {
        $draft = $this->drafts->findByUid($context->link->draftUid);
        if ($draft === null || !$draft->isDraft()) {
            throw new ReviewRefusedException(ReviewRefusedException::CONFLICT, 'The draft is closed.');
        }

        return $draft;
    }

    /**
     * A section of this draft, by its id; an id from another draft is not
     * among its sections.
     *
     * @return array<string, mixed>
     */
    private function sectionOf(WorkspaceDraft $draft, string $sectionId): array
    {
        foreach ($draft->sections as $section) {
            if ($sectionId !== '' && ($section['id'] ?? null) === $sectionId) {
                return $section;
            }
        }

        throw new ReviewRefusedException(ReviewRefusedException::INVALID, 'The section was not found.');
    }

    private function assertRoom(WorkspaceDraft $draft): void
    {
        if ($this->comments->countByDraft($draft->uid) >= self::MAX_ENTRIES_PER_DRAFT) {
            throw new ReviewRefusedException(ReviewRefusedException::LIMIT, 'This draft has reached its limit of comments and changes.');
        }
    }

    private function assertField(string $field, bool $mayBeEmpty): void
    {
        if (!in_array($field, self::FIELDS, true) && !($mayBeEmpty && $field === '')) {
            throw new ReviewRefusedException(ReviewRefusedException::INVALID, 'Unknown field.');
        }
    }

    private function maxFor(string $field): int
    {
        return $field === 'bodytext' ? ReviewInput::SUGGESTION_MAX : self::SHORT_FIELD_MAX;
    }

    private function text(string $raw, int $max, ?int $fieldMax = null): string
    {
        // Invalid UTF-8 would be cleaned to an empty string, which an edit
        // would then take as "delete this field": refuse it instead.
        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw new ReviewRefusedException(ReviewRefusedException::INVALID, 'The text is not valid UTF-8.');
        }
        $text = ReviewInput::text($raw);
        if ($text === '' && $fieldMax === null) {
            throw new ReviewRefusedException(ReviewRefusedException::INVALID, 'The text is empty.');
        }
        if (mb_strlen($text) > min($max, $fieldMax ?? $max)) {
            throw new ReviewRefusedException(ReviewRefusedException::INVALID, 'The text is too long.');
        }

        return $text;
    }
}
