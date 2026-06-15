<?php

namespace Tests\Feature\User;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UploadPhotoTest extends TestCase
{
    use RefreshDatabase;

public function test_user_can_upload_photo(): void
{
    $this->markTestIncomplete(
        'À compléter avec mock Cloudinary'
    );
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