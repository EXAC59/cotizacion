<?php

namespace Tests\Unit;

use App\Support\CompanyBranchesParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyBranchesParserTest extends TestCase
{
    #[Test]
    public function it_parses_pipe_separated_branch_lines(): void
    {
        $text = <<<'TXT'
Matriz|Colima Esq. Guillermo Prieto #415 La Paz, B.C.S|Tels. (612) 125 6111 / (612) 122 3742
Suc. Abasolo|Mariano Abasolo Esq. Colima (Plaza Nautica) La Paz, B.C.S|Tel. (612) 125 4360
TXT;

        $branches = CompanyBranchesParser::parse($text);

        $this->assertCount(2, $branches);
        $this->assertSame('Matriz', $branches[0]['label']);
        $this->assertSame('Colima Esq. Guillermo Prieto #415 La Paz, B.C.S', $branches[0]['address']);
        $this->assertSame('Tels. (612) 125 6111 / (612) 122 3742', $branches[0]['phone']);
    }

    #[Test]
    public function it_parses_legacy_colon_format(): void
    {
        $text = 'Matriz: Colima 415 La Paz, B.C.S — Tels. (612) 125 6111';

        $branches = CompanyBranchesParser::parse($text);

        $this->assertSame('Matriz', $branches[0]['label']);
        $this->assertStringContainsString('Colima 415', $branches[0]['address']);
        $this->assertSame('Tels. (612) 125 6111', $branches[0]['phone']);
    }
}
