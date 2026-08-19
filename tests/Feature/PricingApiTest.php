<?php



namespace Tests\Feature;




use PHPUnit\Framework\Attributes\Test;

use Tests\AuthenticatedFeatureTestCase;



class PricingApiTest extends AuthenticatedFeatureTestCase

{




    #[Test]

    public function it_returns_commercial_settings(): void

    {

        $response = $this->getJson('/api/configuracion/comercial');



        $response->assertOk()

            ->assertJsonPath('defaultMarginPercent', 30)

            ->assertJsonPath('taxPercent', 16);

    }



    #[Test]

    public function it_updates_default_margin(): void

    {

        $response = $this->patchJson('/api/configuracion/comercial', [

            'defaultMarginPercent' => 35,

        ]);



        $response->assertOk()->assertJsonPath('defaultMarginPercent', 35);

        $this->assertDatabaseHas('app_settings', [

            'id' => 1,

            'default_margin_percent' => 35,

        ]);

    }



    #[Test]

    public function it_saves_pdf_company_fields_with_empty_emails(): void

    {

        $response = $this->patchJson('/api/configuracion/comercial', [

            'companyName' => 'EXACTO',

            'companyLegalName' => 'Expertos en Administración y Computo S.A de C.V',

            'companyTagline' => 'Solo los mejores componentes',

            'companyBranches' => 'Matriz|Colima #415 La Paz|Tels. (612) 125 6111',

            'companyEmail' => '',

            'quoteSignatureEmail' => '',

            'bankAccounts' => [

                [

                    'bank' => 'BANAMEX',

                    'account' => '962-32198',

                    'clabe' => '002040096200321988',

                    'currency' => 'M.N.',

                ],

            ],

        ]);



        $response->assertOk()

            ->assertJsonPath('companyName', 'EXACTO')

            ->assertJsonPath('companyLegalName', 'Expertos en Administración y Computo S.A de C.V')

            ->assertJsonPath('companyTagline', 'Solo los mejores componentes')

            ->assertJsonPath('companyEmail', null)

            ->assertJsonPath('quoteSignatureEmail', null);



        $this->assertDatabaseHas('app_settings', [

            'id' => 1,

            'company_name' => 'EXACTO',

            'company_tagline' => 'Solo los mejores componentes',

        ]);

    }



    #[Test]

    public function it_calculates_quote_pricing(): void

    {

        $response = $this->postJson('/api/cotizaciones/calcular', [

            'globalMarginPercent' => 30,

            'taxPercent' => 16,

            'lines' => [

                ['quantity' => 2, 'cost' => 1000, 'marginPercent' => 30],

            ],

        ]);



        $response->assertOk()

            ->assertJsonPath('lines.0.salePrice', 1300)

            ->assertJsonPath('lines.0.amount', 2600)

            ->assertJsonPath('totals.totalProfit', 600)

            ->assertJsonPath('total', 3016);

    }



    #[Test]

    public function it_respects_uses_global_margin_on_calculate(): void

    {

        $response = $this->postJson('/api/cotizaciones/calcular', [

            'globalMarginPercent' => 30,

            'taxPercent' => 16,

            'lines' => [

                ['quantity' => 1, 'cost' => 1000, 'marginPercent' => 30, 'usesGlobalMargin' => true],

                ['quantity' => 1, 'cost' => 1000, 'marginPercent' => 50, 'usesGlobalMargin' => false],

            ],

        ]);



        $response->assertOk()

            ->assertJsonPath('lines.0.salePrice', 1300)

            ->assertJsonPath('lines.1.salePrice', 1500);

    }

}


