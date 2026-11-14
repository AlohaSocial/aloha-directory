<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\PauseKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PauseKindTest extends TestCase
{
    #[DataProvider('kinds')]
    public function testKinds(string $value, bool $automatic, string $label): void
    {
        $kind = PauseKind::from($value);
        self::assertSame($automatic, $kind->isAutomatic());
        self::assertSame($label, $kind->label());
    }

    public static function kinds(): iterable
    {
        yield 'stale' => ['stale', true, 'No heartbeat for 7 days'];
        yield 'checks' => ['checks', true, 'Automatic checks failing'];
        yield 'federation' => ['federation', true, 'Federation failing for 24 hours'];
        yield 'version' => ['version', true, 'Below the minimum version'];
        yield 'curator' => ['curator', false, 'Paused by a curator'];
    }

    public function testEveryCaseIsCovered(): void
    {
        // The stored values are persisted in the database: renaming one needs a migration.
        self::assertSame(['stale', 'checks', 'federation', 'version', 'curator'], array_map(static fn (PauseKind $k): string => $k->value, PauseKind::cases()));
        self::assertSame([PauseKind::Curator], array_values(array_filter(PauseKind::cases(), static fn (PauseKind $k): bool => !$k->isAutomatic())), 'only a curator pause needs curators to lift it');
        self::assertCount(\count(PauseKind::cases()), array_unique(array_map(static fn (PauseKind $k): string => $k->label(), PauseKind::cases())));
        self::assertNull(PauseKind::tryFrom('Stale'));
    }
}
