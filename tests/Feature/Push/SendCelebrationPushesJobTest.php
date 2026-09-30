<?php

namespace Tests\Feature\Push;

use App\Models\Celebration;
use App\Models\CelebrationType;
use App\Models\Church;
use App\Models\DeviceToken;
use App\Models\User;
use App\Modules\Push\Application\Jobs\SendCelebrationPushesJob;
use App\Modules\Push\Application\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class SendCelebrationPushesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function deviceToken(User $user, string $timezone = 'UTC'): DeviceToken
    {
        return DeviceToken::create([
            'userId' => $user->id,
            'token' => 'fake-token-' . $user->id,
            'platform' => DeviceToken::PLATFORM_ANDROID,
            'timezone' => $timezone,
        ]);
    }

    private function mockPush(): PushNotificationService
    {
        $push = Mockery::mock(PushNotificationService::class);
        $this->app->instance(PushNotificationService::class, $push);
        return $push;
    }

    private function runJob(): void
    {
        (new SendCelebrationPushesJob())->handle(app(PushNotificationService::class));
    }

    // =========================================================================
    // ANNIVERSAIRE
    // =========================================================================

    public function test_sends_birthday_push_at_6am_local_when_it_matches(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 06:05:00', 'UTC'));

        $church = Church::factory()->create();
        $user = User::factory()->create(['churchId' => $church->id, 'dateNaissance' => '1990-07-02']);
        $this->deviceToken($user);

        $push = $this->mockPush();
        $push->shouldReceive('sendToUsers')->once()->withArgs(function ($users, $title, $body, $data) use ($user) {
            return collect($users)->pluck('id')->contains($user->id)
                && str_contains($body, $user->nom)
                && $data['type'] === 'celebration';
        });

        $this->runJob();

        $this->assertTrue(
            Celebration::where('userId', $user->id)->whereDate('occursOn', '2026-07-02')->exists()
        );
    }

    public function test_does_not_send_outside_6am_local_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 14:00:00', 'UTC'));

        $church = Church::factory()->create();
        $user = User::factory()->create(['churchId' => $church->id, 'dateNaissance' => '1990-07-02']);
        $this->deviceToken($user);

        $push = $this->mockPush();
        $push->shouldNotReceive('sendToUsers');

        $this->runJob();

        $this->assertDatabaseMissing('celebrations', ['userId' => $user->id]);
    }

    public function test_does_not_send_twice_the_same_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 06:05:00', 'UTC'));

        $church = Church::factory()->create();
        $user = User::factory()->create(['churchId' => $church->id, 'dateNaissance' => '1990-07-02']);
        $this->deviceToken($user);

        $push = $this->mockPush();
        $push->shouldReceive('sendToUsers')->once();

        $this->runJob();
        $this->runJob();

        $this->assertEquals(1, Celebration::where('userId', $user->id)->count());
    }

    // =========================================================================
    // OCCASION FIXE — Nouvel An (01-01), pour tous
    // =========================================================================

    public function test_sends_new_year_push_to_all_active_users_on_january_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-01 06:00:00', 'UTC'));

        $church = Church::factory()->create();
        $user = User::factory()->create(['churchId' => $church->id, 'dateNaissance' => null]);
        $this->deviceToken($user);

        $push = $this->mockPush();
        $push->shouldReceive('sendToUsers')->once()->withArgs(
            fn ($users, $title, $body, $data) => $data['type'] === 'celebration'
        );

        $this->runJob();

        $type = CelebrationType::where('key', CelebrationType::KEY_NOUVEL_AN)->firstOrFail();
        $this->assertTrue(
            Celebration::where('userId', $user->id)
                ->where('celebrationTypeId', $type->id)
                ->whereDate('occursOn', '2027-01-01')
                ->exists()
        );
    }

    public function test_inactive_type_never_fires(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-01 06:00:00', 'UTC'));
        CelebrationType::where('key', CelebrationType::KEY_NOUVEL_AN)->update(['active' => false]);

        $church = Church::factory()->create();
        $user = User::factory()->create(['churchId' => $church->id]);
        $this->deviceToken($user);

        $push = $this->mockPush();
        $push->shouldNotReceive('sendToUsers');

        $this->runJob();
    }

    // =========================================================================
    // CIBLAGE GENRE — Journée de la femme (03-08), femmes uniquement
    // =========================================================================

    public function test_womens_day_only_targets_female_users(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-08 06:00:00', 'UTC'));

        $church = Church::factory()->create();
        $femme = User::factory()->create(['churchId' => $church->id, 'sexe' => 'femme']);
        $homme = User::factory()->create(['churchId' => $church->id, 'sexe' => 'homme']);
        $this->deviceToken($femme);
        $this->deviceToken($homme);

        $push = $this->mockPush();
        $push->shouldReceive('sendToUsers')->once()->withArgs(
            fn ($users) => collect($users)->pluck('id')->contains($femme->id)
        );

        $this->runJob();

        $type = CelebrationType::where('key', CelebrationType::KEY_JOURNEE_FEMME)->firstOrFail();
        $this->assertDatabaseHas('celebrations', ['userId' => $femme->id, 'celebrationTypeId' => $type->id]);
        $this->assertDatabaseMissing('celebrations', ['userId' => $homme->id, 'celebrationTypeId' => $type->id]);
    }

    // =========================================================================
    // FÊTE MOBILE — Pâques (calculée)
    // =========================================================================

    public function test_easter_push_fires_on_the_correct_computed_date(): void
    {
        // Pâques 2026 = 5 avril (vérifié indépendamment via easter_date()).
        Carbon::setTestNow(Carbon::parse('2026-04-05 06:00:00', 'UTC'));

        $church = Church::factory()->create();
        $user = User::factory()->create(['churchId' => $church->id]);
        $this->deviceToken($user);

        $push = $this->mockPush();
        $push->shouldReceive('sendToUsers')->once();

        $this->runJob();

        $type = CelebrationType::where('key', CelebrationType::KEY_PAQUES)->firstOrFail();
        $this->assertDatabaseHas('celebrations', ['userId' => $user->id, 'celebrationTypeId' => $type->id]);
    }

    public function test_easter_push_does_not_fire_the_day_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-04 06:00:00', 'UTC'));

        $church = Church::factory()->create();
        $user = User::factory()->create(['churchId' => $church->id]);
        $this->deviceToken($user);

        $type = CelebrationType::where('key', CelebrationType::KEY_PAQUES)->firstOrFail();

        $push = $this->mockPush();
        // D'autres types actifs peuvent matcher ce jour — on vérifie
        // seulement l'absence de la ligne Pâques.
        $push->shouldReceive('sendToUsers')->zeroOrMoreTimes();

        $this->runJob();

        $this->assertDatabaseMissing('celebrations', ['userId' => $user->id, 'celebrationTypeId' => $type->id]);
    }
}
