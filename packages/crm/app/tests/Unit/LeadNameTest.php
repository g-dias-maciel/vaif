<?php

namespace Tests\Unit;

use App\Models\Lead;
use PHPUnit\Framework\TestCase;

class LeadNameTest extends TestCase
{
    public function test_names_are_trimmed_collapsed_and_title_cased(): void
    {
        $lead = new Lead(['name' => '  jOSE   DA   sILVA ']);

        $this->assertSame('Jose da Silva', $lead->name);
        $this->assertSame('Jose da Silva', $lead->displayName());
    }

    public function test_an_empty_name_falls_back_to_sem_nome(): void
    {
        $lead = new Lead(['name' => '   ']);

        $this->assertNull($lead->name);
        $this->assertSame('Sem nome', $lead->displayName());
    }
}
