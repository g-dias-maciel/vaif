<?php

namespace Tests\Unit;

use App\Casts\PgTextArray;
use App\Models\ArtistSetting;
use PHPUnit\Framework\TestCase;

class PgTextArrayTest extends TestCase
{
    private function cast(): PgTextArray
    {
        return new PgTextArray();
    }

    public function test_it_reads_a_postgres_array_literal(): void
    {
        $model = new ArtistSetting();

        $this->assertSame(
            ['realismo', 'blackwork'],
            $this->cast()->get($model, 'specialties', '{realismo,blackwork}', []),
        );
        $this->assertSame(
            ['old school', 'fine line'],
            $this->cast()->get($model, 'specialties', '{"old school","fine line"}', []),
        );
        $this->assertSame([], $this->cast()->get($model, 'specialties', '{}', []));
        $this->assertSame([], $this->cast()->get($model, 'specialties', null, []));
    }

    public function test_it_writes_a_postgres_array_literal(): void
    {
        $model = new ArtistSetting();

        $this->assertSame(
            '{"5511999990001","5511999990002"}',
            $this->cast()->set($model, 'blocked_numbers', ['5511999990001', '5511999990002'], []),
        );
        $this->assertSame('{}', $this->cast()->set($model, 'blocked_numbers', [], []));
        $this->assertNull($this->cast()->set($model, 'blocked_numbers', null, []));
    }
}
