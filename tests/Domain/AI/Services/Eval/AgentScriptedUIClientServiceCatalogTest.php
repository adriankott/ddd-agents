<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The scripted client's `listScreens` answer names the filter values as the web client does: in an answer narrowed to
 * at most four screens, and always for the screen whose key is the search text (it comes first).
 */
class AgentScriptedUIClientServiceCatalogTest extends UnitTestCase
{
    public function testAScreenNamedByItsKeyComesFirstWithItsFilterValues(): void
    {
        $scriptedClient = new CatalogScriptedUIClientServiceDouble();
        $scriptedClient->catalog = [
            'lines' => [
                '- order-archive · Order archive · filters year',
                '- order-returns · Order returns',
                '- order-notes · Order notes',
                '- order-exports · Order exports',
                '- order · Orders (order) · filters status',
                '- invoices · Invoices · filters state',
            ],
            'filterValues' => ['order' => 'status one of OPEN|SOLVED', 'order-archive' => 'year a year', 'invoices' => 'state one of DUE|PAID'],
        ];

        $byKey = explode("\n", $scriptedClient->listScreens('order'));
        $narrowed = $scriptedClient->listScreens('invoices');
        $whole = $scriptedClient->listScreens('');

        self::assertSame('Screens this user can open (5). Pass the key to openScreen. Filter values of a screen: listScreens with its key as searchText.', $byKey[0]);
        self::assertSame('- order · Orders (order) · filters status', $byKey[1]);
        self::assertSame('  filter values: status one of OPEN|SOLVED', $byKey[2]);
        self::assertSame('- order-archive · Order archive · filters year', $byKey[3], 'five screens match: the others name their keys only');
        self::assertSame("Screens this user can open (1). Pass the key to openScreen.\n- invoices · Invoices · filters state\n  filter values: state one of DUE|PAID", $narrowed);
        self::assertStringNotContainsString('filter values:', $whole);
        self::assertStringContainsString('(6)', $whole);
    }
}
