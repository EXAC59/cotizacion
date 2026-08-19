<?php



namespace Tests\Feature;



use App\Models\Client;

use App\Models\Quote;

use App\Models\User;

use Illuminate\Support\Facades\DB;


use PHPUnit\Framework\Attributes\Test;

use Tests\AuthenticatedFeatureTestCase;



class CotizacionListTest extends AuthenticatedFeatureTestCase

{




    #[Test]

    public function it_lists_and_shows_quotes(): void

    {

        $client = Client::query()->create([

            'company' => 'List Test SA',

            'rfc' => 'LST010101ABC',

        ]);



        $roleId = DB::table('roles')->where('slug', 'ventas')->value('id');

        $user = User::factory()->create([

            'name' => 'Vendedor Listado',

            'email' => 'vendedor-list@test.com',

            'role_id' => $roleId,

        ]);

        $this->actingAs($user);



        $store = $this->postJson('/api/cotizaciones', [

            'folio' => 'COT-LIST-001',

            'clientId' => $client->id,

            'lines' => [

                [

                    'quantity' => 1,

                    'product' => 'Item',

                    'partNumber' => 'SKU-1',

                    'cost' => 100,

                    'marginPercent' => 30,

                ],

            ],

        ]);



        $store->assertCreated();

        $id = $store->json('id');
        $folio = $store->json('folio');

        $this->getJson('/api/cotizaciones')
            ->assertOk()
            ->assertJsonPath('data.0.folio', $folio)

            ->assertJsonPath('data.0.createdByName', 'Vendedor Listado');



        $this->getJson("/api/cotizaciones/{$id}")

            ->assertOk()

            ->assertJsonPath('lines.0.partNumber', 'SKU-1');



        $this->assertSame(1, Quote::count());

    }

}


