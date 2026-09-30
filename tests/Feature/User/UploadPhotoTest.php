<?php

namespace Tests\Feature\User;

use App\Core\Services\ImageStorageService;
use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UploadPhotoTest extends TestCase
{
    use RefreshDatabase;

    /** Remplace ImageStorageService par un mock qui n'écrit jamais sur disque. */
    private function mockImageUpload(): void
    {
        $this->mock(ImageStorageService::class, function ($mock) {
            $mock->shouldReceive('generateUserFilename')
                ->andReturnUsing(fn ($userId) => "user_{$userId}");
            $mock->shouldReceive('uploadProfilePhoto')
                ->andReturnUsing(fn ($file, $filename) => "https://api.test/storage/profiles/{$filename}.jpg");
            $mock->shouldReceive('deleteByUrl')->andReturnNull();
        });
    }

public function test_user_can_upload_photo(): void
{
    $this->markTestIncomplete(
        "À compléter avec mock ImageStorageService"
    );
}

    /**
     * Tous les rôles peuvent modifier leur propre photo (avant, réservé au
     * pasteur — voir assertCanUploadPhoto dans UserService).
     */
    public function test_any_role_can_upload_their_own_photo(): void
    {
        $this->mockImageUpload();

        $church = Church::factory()->create();
        $paroissien = User::factory()->create([
            'role' => 'paroissien',
            'churchId' => $church->id,
        ]);

        $file = UploadedFile::fake()->image('moi.jpg');
        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post("/api/v1/users/{$paroissien->id}/photo", ['photo' => $file]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.photoUrl'));
    }

public function test_photo_is_required(): void
{
    $church = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => $church->id,
    ]);

    $user = User::factory()->create([
        'churchId' => $church->id,
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson(
        "/api/v1/users/{$user->id}/photo",
        []
    );

    $response->assertStatus(422);
}


public function test_pdf_is_rejected(): void
{
    $church = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => $church->id,
    ]);

    $user = User::factory()->create([
        'churchId' => $church->id,
    ]);

    $file = UploadedFile::fake()->create(
        'document.pdf',
        100,
        'application/pdf'
    );

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->post(
        "/api/v1/users/{$user->id}/photo",
        [
            'photo' => $file,
        ]
    );

    $response->assertStatus(422);
}

public function test_photo_too_large_is_rejected(): void
{
    $church = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => $church->id,
    ]);

    $user = User::factory()->create([
        'churchId' => $church->id,
    ]);

    $file = UploadedFile::fake()->image(
        'large.jpg'
    )->size(3000);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->post(
        "/api/v1/users/{$user->id}/photo",
        [
            'photo' => $file,
        ]
    );

    $response->assertStatus(422);
}

public function test_pasteur_cannot_upload_photo_for_other_church_user(): void
{
    $church1 = Church::factory()->create();
    $church2 = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => $church1->id,
    ]);

    $user = User::factory()->create([
        'churchId' => $church2->id,
    ]);

    $file = UploadedFile::fake()->image('photo.jpg');

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->post(
        "/api/v1/users/{$user->id}/photo",
        [
            'photo' => $file,
        ]
    );

    $response->assertStatus(403);
}
}