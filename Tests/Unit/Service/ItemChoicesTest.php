<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\ItemChoices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A value the model invented is left out of an item instead of refusing the
 * page, and long lists of choices stand once in the prompt (NEXT-228/229).
 */
#[CoversClass(ItemChoices::class)]
final class ItemChoicesTest extends UnitTestCase
{
    private const FIELDS = [
        'header' => ['type' => 'input'],
        // Described before the column it depends on: the order must not matter.
        'icon' => ['type' => 'select', 'itemsBy' => ['column' => 'icon_set', 'items' => ['Ionicons' => ['home', 'alert'], 'Glyphicons' => ['adjust']]]],
        'icon_set' => ['type' => 'select', 'items' => ['Glyphicons', 'Ionicons']],
        'wide' => ['type' => 'check', 'items' => ['x']],
    ];

    /**
     * @return iterable<string, array{array<string, string>, array<string, string>}>
     */
    public static function items(): iterable
    {
        yield 'an icon of its set' => [['header' => 'A', 'icon' => 'home', 'icon_set' => 'Ionicons'], ['header' => 'A', 'icon' => 'home', 'icon_set' => 'Ionicons']];
        yield 'another case' => [['icon' => 'HOME', 'icon_set' => 'ionicons'], ['icon' => 'home', 'icon_set' => 'Ionicons']];
        yield 'an invented icon' => [['header' => 'A', 'icon' => 'rocket', 'icon_set' => 'Ionicons'], ['header' => 'A', 'icon_set' => 'Ionicons']];
        yield 'an icon of another set' => [['icon' => 'home', 'icon_set' => 'Glyphicons'], ['icon_set' => 'Glyphicons']];
        yield 'an icon without a set' => [['icon' => 'home'], []];
        yield 'an invented set takes its icon along' => [['icon' => 'home', 'icon_set' => 'Feather'], []];
        yield 'a check is no choice' => [['wide' => '1'], ['wide' => '1']];
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $expected
     */
    #[Test]
    #[DataProvider('items')]
    public function onlyChoicesAreKept(array $row, array $expected): void
    {
        self::assertSame($expected, ItemChoices::keep($row, self::FIELDS));
    }

    #[Test]
    public function aLongListStandsOnce(): void
    {
        $long = array_map(static fn(int $number): string => 'icon-' . $number, range(1, 31));
        $collection = ['table' => 't', 'maxItems' => 3, 'fields' => [
            'set' => ['items' => ['A', 'B']],
            'icon' => ['itemsBy' => ['column' => 'set', 'items' => ['A' => $long, 'B' => ['x']]]],
        ]];

        $compact = ItemChoices::compact(['icon_group' => ['c' => $collection], 'timeline' => ['c' => $collection]]);

        self::assertSame(['Liste 1' => implode(', ', $long)], $compact['lists']);
        $json = json_encode($compact['described'], JSON_THROW_ON_ERROR);
        self::assertSame(2, substr_count($json, '"A":"Liste 1","B":["x"]'));
        self::assertStringNotContainsString('icon-31', $json);
        self::assertStringContainsString('"items":["A","B"]', $json);
    }
}
