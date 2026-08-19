<?php

namespace Tests\Feature;

use App\Models\ComparatorSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class ComparatorSettingsTest extends AuthenticatedFeatureTestCase
{
    #[Test]
    public function it_returns_default_comparator_settings(): void
    {
        $response = $this->getJson('/api/configuracion/comparador');

        $response->assertOk()
            ->assertJsonStructure([
                'weights' => ['price', 'stock', 'warehouse', 'performance', 'preferred'],
                'warehousePriority',
                'availableWarehouses',
                'preferredWholesalerIds',
                'importPenalty',
                'leadDayPenalty',
                'minStockThreshold',
            ]);
        $this->assertGreaterThanOrEqual(30, count($response->json('availableWarehouses')));
        $this->assertGreaterThanOrEqual(30, count($response->json('warehousePriority')));
    }

    #[Test]
    public function it_updates_comparator_settings(): void
    {
        $response = $this->patchJson('/api/configuracion/comparador', [
            'weights' => [
                'price' => 0.5,
                'stock' => 0.2,
                'warehouse' => 0.15,
                'performance' => 0.1,
                'preferred' => 0.05,
            ],
            'importPenalty' => 0.1,
            'warehousePriority' => ['MTY', 'CDMX'],
        ]);

        $response->assertOk()
            ->assertJsonPath('weights.price', 0.5)
            ->assertJsonPath('importPenalty', 0.1)
            ->assertJsonPath('warehousePriority.0', 'MTY');

        $this->assertSame(0.1, (float) ComparatorSetting::current()->import_penalty);
    }

    #[Test]
    public function it_manages_user_comparator_preferences(): void
    {
        $roleId = DB::table('roles')->where('slug', 'gerente_compras')->value('id');
        $user = User::factory()->create([
            'email' => 'comparator@test.local',
            'role_id' => $roleId,
        ]);
        $this->actingAs($user);

        $get = $this->getJson('/api/configuracion/comparador/preferencias');
        $get->assertOk()
            ->assertJsonPath('preferredWarehouse', 'D2A')
            ->assertJsonPath('autoApplyBest', false);
        $prefs = $get->json('preferredWarehouses');
        $this->assertIsArray($prefs);
        $this->assertContains('D2A', $prefs);
        $this->assertContains('53A', $prefs);

        $patch = $this->patchJson('/api/configuracion/comparador/preferencias', [
            'preferredWarehouses' => ['35A', '01A'],
            'autoApplyBest' => true,
        ]);

        $patch->assertOk()
            ->assertJsonPath('autoApplyBest', true);
        $saved = $patch->json('preferredWarehouses');
        $this->assertSame('D2A', $saved[0] ?? null);
        $this->assertSame('53A', $saved[1] ?? null);
        $this->assertSame('35A', $saved[2] ?? null);
        $this->assertContains('01A', $saved);

        $this->assertDatabaseHas('user_comparator_preferences', [
            'user_id' => $user->id,
            'preferred_warehouse' => 'D2A',
            'auto_apply_best' => true,
        ]);
    }
}
