<?php

namespace Tests\Feature\Payment;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use App\Models\PaymentTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private function createTransaction(
        User $user,
        string $status = 'pending'
    ): PaymentTransaction {
        return PaymentTransaction::create([
            'userId'           => $user->id,
            'churchId'         => $user->churchId,
            'paymentOperator'  => 'ORANGE MONEY',
            'paymentNumber'    => '699112233',
            'amountPerMonth'   => 1000,
            'monthsCount'      => 1,
            'totalAmount'      => 1000,
            'fees'             => 0,
            'amountCharged'    => 1000,
            'status'           => $status,
            'signatureType'    => 'distance',
        ]);
    }

    public function test_guest_cannot_check_payment_status(): void
    {
        $response = $this->getJson(
            '/api/v1/signatures/transaction/1/status'
        );

        $response->assertStatus(401);
    }

    public function test_owner_can_check_pending_transaction(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $transaction = $this->createTransaction(
            $user,
            'pending'
        );

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $token
        )->getJson(
            "/api/v1/signatures/transaction/{$transaction->id}/status"
        );

        $response->assertStatus(200);

        $response->assertJsonPath(
            'data.status',
            'pending'
        );

        $response->assertJsonPath(
            'data.is_terminal',
            false
        );

        $response->assertJsonPath(
            'data.transaction_id',
            $transaction->id
        );
    }

    public function test_owner_can_check_completed_transaction(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $transaction = $this->createTransaction(
            $user,
            'completed'
        );

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $token
        )->getJson(
            "/api/v1/signatures/transaction/{$transaction->id}/status"
        );

        $response->assertStatus(200);

        $response->assertJsonPath(
            'data.status',
            'completed'
        );

        $response->assertJsonPath(
            'data.is_terminal',
            true
        );
    }

    public function test_owner_can_check_failed_transaction(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $transaction = $this->createTransaction(
            $user,
            'failed'
        );

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $token
        )->getJson(
            "/api/v1/signatures/transaction/{$transaction->id}/status"
        );

        $response->assertStatus(200);

        $response->assertJsonPath(
            'data.status',
            'failed'
        );

        $response->assertJsonPath(
            'data.is_terminal',
            true
        );
    }

    public function test_user_cannot_check_another_user_transaction(): void
    {
        $church = Church::factory()->create();

        $owner = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $intruder = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $transaction = $this->createTransaction(
            $owner,
            'pending'
        );

        $token = $intruder->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $token
        )->getJson(
            "/api/v1/signatures/transaction/{$transaction->id}/status"
        );

        $response->assertStatus(404);
    }

    public function test_transaction_not_found(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $token
        )->getJson(
            '/api/v1/signatures/transaction/999999/status'
        );

        $response->assertStatus(404);
    }
}