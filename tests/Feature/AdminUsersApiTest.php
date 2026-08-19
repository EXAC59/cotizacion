<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\AuthenticatedFeatureTestCase;

class AdminUsersApiTest extends AuthenticatedFeatureTestCase
{
    public function test_admin_can_list_create_update_and_delete_users(): void
    {
        $this->actingAsDemoUser('administrador');

        $list = $this->getJson('/api/admin/users');
        $list->assertOk()->assertJsonStructure(['data']);

        $create = $this->postJson('/api/admin/users', [
            'name' => 'Nuevo Vendedor',
            'username' => 'nuevo.vendedor',
            'email' => 'nuevo.vendedor@exacto.test',
            'role' => 'ventas',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
            'folioCode' => 'NUEVO',
            'active' => true,
        ]);
        $create->assertCreated()
            ->assertJsonPath('email', 'nuevo.vendedor@exacto.test')
            ->assertJsonPath('username', 'nuevo.vendedor')
            ->assertJsonPath('role', 'ventas')
            ->assertJsonPath('hasSignature', false);

        $id = $create->json('id');
        $this->assertNotEmpty($id);

        $update = $this->putJson("/api/admin/users/{$id}", [
            'name' => 'Vendedor Actualizado',
            'username' => 'nuevo.vendedor',
            'email' => 'nuevo.vendedor@exacto.test',
            'role' => 'ventas',
            'folioCode' => 'NUEVO',
            'active' => false,
        ]);
        $update->assertOk()
            ->assertJsonPath('name', 'Vendedor Actualizado')
            ->assertJsonPath('active', false);

        $delete = $this->deleteJson("/api/admin/users/{$id}");
        $delete->assertOk();

        $this->assertNull(User::query()->where('email', 'nuevo.vendedor@exacto.test')->first());
    }

    public function test_admin_can_upload_and_remove_user_signature(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->actingAsDemoUser('administrador');

        $create = $this->post('/api/admin/users', [
            'name' => 'Con Firma',
            'username' => 'con.firma',
            'email' => 'con.firma@exacto.test',
            'role' => 'ventas',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
            'folioCode' => 'FIRMA',
            'active' => '1',
            'signature' => UploadedFile::fake()->image('firma.png', 200, 80),
        ], ['Accept' => 'application/json']);

        $create->assertCreated()
            ->assertJsonPath('hasSignature', true);
        $signatureUrl = (string) $create->json('signatureUrl');
        $this->assertNotEmpty($signatureUrl);
        $this->assertStringContainsString('/api/admin/users/', $signatureUrl);
        $this->assertStringContainsString('/signature', $signatureUrl);
        $this->assertStringNotContainsString('/storage/', $signatureUrl);

        $id = $create->json('id');
        $user = User::query()->where('uuid', $id)->first()
            ?? (ctype_digit((string) $id) ? User::query()->find((int) $id) : null);
        $this->assertNotNull($user);
        $this->assertNotEmpty($user->signature_path);
        Storage::disk('local')->assertExists($user->signature_path);

        $this->getJson("/api/admin/users/{$id}/signature")->assertOk();

        $remove = $this->putJson("/api/admin/users/{$id}", [
            'name' => 'Con Firma',
            'username' => 'con.firma',
            'email' => 'con.firma@exacto.test',
            'role' => 'ventas',
            'folioCode' => 'FIRMA',
            'active' => true,
            'removeSignature' => true,
        ]);
        $remove->assertOk()->assertJsonPath('hasSignature', false);

        $user->refresh();
        $this->assertNull($user->signature_path);
        $this->getJson("/api/admin/users/{$id}/signature")->assertNotFound();
    }

    public function test_guest_cannot_fetch_user_signature(): void
    {
        Storage::fake('local');
        $this->actingAsDemoUser('administrador');

        $create = $this->post('/api/admin/users', [
            'name' => 'Firma Privada',
            'username' => 'firma.privada',
            'email' => 'firma.privada@exacto.test',
            'role' => 'ventas',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
            'folioCode' => 'PRIV',
            'active' => '1',
            'signature' => UploadedFile::fake()->image('firma.png', 200, 80),
        ], ['Accept' => 'application/json']);

        $create->assertCreated();
        $id = $create->json('id');

        Auth::forgetGuards();
        $this->flushSession();
        $this->getJson("/api/admin/users/{$id}/signature")->assertUnauthorized();
    }

    public function test_ventas_cannot_manage_users(): void
    {
        $this->actingAsDemoUser('ventas');

        $this->getJson('/api/admin/users')->assertForbidden();
    }
}
