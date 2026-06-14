<?php

namespace App\Modules\Auth\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\LoginAttempt;
use Illuminate\Support\Facades\Cache;

/**
 * Rate limiting applicatif + blocage progressif après échecs login.
 */
class RateLimitService
{
    private array $config;

    public function __construct()
    {
        $this->config = config('security');
    }

    /**
     * Clé de rate limit (email, email|ip, ip:xxx).
     */
    public function cacheKey(string $action, string $identifier): string
    {
        return 'ratelimit:' . $action . ':' . md5($identifier);
    }

    /**
     * Lance 429 si la limite configurée est dépassée.
     */
    public function ensureWithinLimit(string $action, string $identifier): void
    {
        if (!$this->isRateLimited($action, $identifier)) {
            return;
        }

        $decayMinutes = $this->config['rate_limiting'][$action]['decay_minutes'] ?? 15;

        throw ApiException::tooManyRequests(
            'Trop de tentatives. Réessayez dans quelques minutes.',
            ['retry_after' => $decayMinutes * 60]
        );
    }

    public function isRateLimited(string $action, string $identifier): bool
    {
        $limits = $this->config['rate_limiting'][$action] ?? null;
        if (!$limits) {
            return false;
        }

        $attempts = (int) Cache::get($this->cacheKey($action, $identifier), 0);

        return $attempts >= $limits['max_attempts'];
    }

    public function incrementRateLimit(string $action, string $identifier): void
    {
        $limits = $this->config['rate_limiting'][$action] ?? null;
        if (!$limits) {
            return;
        }

        $key = $this->cacheKey($action, $identifier);
        $decayMinutes = $limits['decay_minutes'];
        $expiresAt = now()->addMinutes($decayMinutes);

        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, $expiresAt);
    }

    public function resetRateLimit(string $action, string $identifier): void
    {
        Cache::forget($this->cacheKey($action, $identifier));
    }

    public function recordFailedLogin(
        string $email,
        string $ipAddress,
        ?string $userAgent = null
    ): array {
        $blocking = $this->config['blocking'];

        $attempt = LoginAttempt::where('email', $email)
            ->where('ip_address', $ipAddress)
            ->where('created_at', '>', now()->subHours(24))
            ->first();

        if (!$attempt) {
            LoginAttempt::create([
                'email'            => $email,
                'ip_address'       => $ipAddress,
                'user_agent'       => $userAgent ?? request()->header('User-Agent'),
                'failed_count'     => 1,
                'first_attempt_at' => now(),
                'status'           => 'pending',
            ]);

            return ['is_blocked' => false, 'remaining_minutes' => 0];
        }

        if ($attempt->blocked_until && now()->isAfter($attempt->blocked_until)) {
            $attempt->update([
                'failed_count'  => 0,
                'blocked_until' => null,
                'status'        => 'pending',
            ]);
        }

        $attempt->increment('failed_count');

        foreach ($blocking['thresholds'] as $threshold) {
            if ($attempt->failed_count >= $threshold['failed_count']) {
                $blockedUntil = now()->addMinutes($threshold['block_minutes']);
                $attempt->update([
                    'blocked_until' => $blockedUntil,
                    'status'        => 'blocked',
                ]);

                return [
                    'is_blocked'        => true,
                    'remaining_minutes' => $attempt->remainingBlockMinutes(),
                ];
            }
        }

        return ['is_blocked' => false, 'remaining_minutes' => 0];
    }

    public function isLoginBlocked(string $email, string $ipAddress): bool
    {
        return (bool) LoginAttempt::where('email', $email)
            ->where('ip_address', $ipAddress)
            ->blocked()
            ->first();
    }

    public function getBlockingInfo(string $email, string $ipAddress): ?array
    {
        $attempt = LoginAttempt::where('email', $email)
            ->where('ip_address', $ipAddress)
            ->blocked()
            ->first();

        if (!$attempt) {
            return null;
        }

        return [
            'remaining_minutes' => $attempt->remainingBlockMinutes(),
            'blocked_until'     => $attempt->blocked_until,
        ];
    }

    public function recordSuccessfulLogin(string $email, string $ipAddress): void
    {
        LoginAttempt::where('email', $email)
            ->where('ip_address', $ipAddress)
            ->update([
                'failed_count'  => 0,
                'status'        => 'success',
                'blocked_until' => null,
            ]);

        $rateKey = strtolower($email) . '|' . $ipAddress;
        $this->resetRateLimit('login', $rateKey);
    }
}
