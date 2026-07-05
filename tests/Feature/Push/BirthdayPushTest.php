<?php

namespace Tests\Feature\Push;

use App\Models\Church;
use App\Models\DeviceToken;
use App\Models\User;
use App\Modules\Push\Application\Jobs\SendBirthdayPushesJob;
use App\Modules\Push\Application\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class BirthdayPushTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function deviceToken(User $user, string $timezone): DeviceToken
    {
        return DeviceToken::create([
            'userId'   => $user->id,
            'token'    => 'fake-token-' . $user->id,
            'platform' => DeviceToken::PLATFORM_ANDROID,
            'timezone' => $timezone,
        ]);
    }

    public function test_sends_birthday_push_when_it_is_6am_locally_and_birthday_matches(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 06:05:00', 'UTC'));

        $church = Church::factory()->create();
        $user = User::factory()->create([
            'churchId' => $church->id,
            'dateNaissance' => '1990-07-02',
        ]);
        $this->deviceToken($user, 'UTC');

        $push = Mockery::mock(PushNotificationService::class);
        $push->shouldReceive('sendToUsers')->once()->withArgs(function ($users, $title, $body, $data) use ($user) {
            return collect($users)->pluck('id')->contains($user->id)
                && str_contains($title, 'anniversaire')
                && str_contains($body, $user->nom);
        });
        $this->app->instance(PushNotificationService::class, $push);

        (new SendBirthdayPushesJob())->handle(app(PushNotificationService::class));

        $this->assertNotNull($user->fresh()->lastBirthdayPushAt);
    }

    public function test_does_not_send_outside_the_6am_local_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 14:00:00', 'UTC'));

        $church = Church::factory()->create();
        $user = User::factory()->create([
            'churchId' => $church->id,
            'dateNaissance' => '1990-07-02',
        ]);
        $this->deviceToken($user, 'UTC');

        $push = Mockery::mock(PushNotificationService::class);
        $push->shouldNotReceive('sendToUsers');
        $this->app->instance(PushNotificationService::class, $push);

        (new SendBirthdayPushesJob())->handle(app(PushNotificationService::class));

        $this->assertNull($user->fresh()->lastBirthdayPushAt);
    }

    public function test_does_not_send_when_birthday_does_not_match_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 06:05:00', 'UTC'));

        $church = Church::factory()->create();
        $user = User::factory()->create([
            'churchId' => $church->id,
            'dateNaissance' => '1990-11-25',
        ]);
        $this->deviceToken($user, 'UTC');

        $push = Mockery::mock(PushNotificationService::class);
        $push->shouldNotReceive('sendToUsers');
        $this->app->instance(PushNotificationService::class, $push);

        (new SendBirthdayPushesJob())->handle(app(PushNotificationService::class));

        $this->assertNull($user->fresh()->lastBirthdayPushAt);
    }

    public function test_does_not_send_twice_the_same_day_even_if_job_runs_multiple_times(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 06:05:00', 'UTC'));

        $church = Church::factory()->create();
        $user = User::factory()->create([
            'churchId' => $church->id,
            'dateNaissance' => '1990-07-02',
        ]);
        $this->deviceToken($user, 'UTC');

        $push = Mockery::mock(PushNotificationService::class);
        $push->shouldReceive('sendToUsers')->once();
        $this->app->instance(PushNotificationService::class, $push);

        // Le job "tourne" deux fois dans la même fenêtre de 15 minutes (retry,
        // scheduler qui se chevauche...) — un seul envoi doit avoir lieu.
        (new SendBirthdayPushesJob())->handle(app(PushNotificationService::class));
        (new SendBirthdayPushesJob())->handle(app(PushNotificationService::class));
    }
}
