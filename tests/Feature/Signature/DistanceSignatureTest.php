<?php

namespace Tests\Feature\Signature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DistanceSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_months_is_required(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'role' => 'paroissien',
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/distance', [
            'amount' => 100,
            'paymentMethod' => 'mobile_money',
            'paymentOperator' => 'ORANGE MONEY',
            'phoneNumber' => '699803165',
        ]);

        $response->assertStatus(422);
    }

    public function test_amount_is_required(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'role' => 'paroissien',
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/distance', [
            'months' => ['2026-01'],
            'paymentMethod' => 'mobile_money',
            'paymentOperator' => 'ORANGE MONEY',
            'phoneNumber' => '699803165',
        ]);

        $response->assertStatus(422);
    }

    public function test_payment_method_is_required(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'role' => 'paroissien',
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/distance', [
            'months' => ['2026-01'],
            'amount' => 100,
            'paymentOperator' => 'ORANGE MONEY',
            'phoneNumber' => '699803165',
        ]);

        $response->assertStatus(422);
    }

    public function test_payment_operator_is_required_for_mobile_money(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'role' => 'paroissien',
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/distance', [
            'months' => ['2026-01'],
            'amount' => 100,
            'paymentMethod' => 'mobile_money',
            'phoneNumber' => '699803165',
        ]);

        $response->assertStatus(422);
    }

    public function test_phone_number_is_required(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'role' => 'paroissien',
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/distance', [
            'months' => ['2026-01'],
            'amount' => 100,
            'paymentMethod' => 'mobile_money',
            'paymentOperator' => 'ORANGE MONEY',
        ]);

        $response->assertStatus(422);
    }

    public function test_invalid_amount_is_rejected(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'role' => 'paroissien',
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/distance', [
            'months' => ['2026-01'],
            'amount' => 12345,
            'paymentMethod' => 'mobile_money',
            'paymentOperator' => 'ORANGE MONEY',
            'phoneNumber' => '699803165',
        ]);

        $response->assertStatus(422);
    }

    public function test_guest_cannot_initiate_distance_signature(): void
    {
        $response = $this->postJson('/api/v1/signatures/distance', [
            'months' => ['2026-01'],
            'amount' => 100,
            'paymentMethod' => 'mobile_money',
            'paymentOperator' => 'ORANGE MONEY',
            'phoneNumber' => '699803165',
        ]);

        $response->assertStatus(401);
    }
}
