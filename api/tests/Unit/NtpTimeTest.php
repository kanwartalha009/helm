<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\NtpTime;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * App-level clock correction (Kanwar, 2026-07-21). These never touch the
 * network: the offset is read from cache, and HELM_MFA_NTP=false (phpunit.xml)
 * short-circuits any real lookup to 0.
 */
class NtpTimeTest extends TestCase
{
    public function test_offset_is_zero_when_ntp_is_disabled(): void
    {
        config(['helm.mfa.ntp.enabled' => false]);
        // Even a cached offset is ignored when disabled — the raw system clock rules.
        Cache::put('helm.mfa.ntp_offset', 99, now()->addHour());

        $this->assertSame(0, (new NtpTime())->offsetSeconds());
    }

    public function test_a_cached_offset_is_applied_without_any_network_call(): void
    {
        config(['helm.mfa.ntp.enabled' => true]);
        Cache::put('helm.mfa.ntp_offset', 45, now()->addHour());

        $ntp = new NtpTime();
        $this->assertSame(45, $ntp->offsetSeconds());

        // now() is system time plus the offset (within a second of the expectation).
        $this->assertEqualsWithDelta(microtime(true) + 45, $ntp->now(), 1.0);
    }

    public function test_time_step_shifts_by_the_offset(): void
    {
        config(['helm.mfa.ntp.enabled' => true]);

        // A +90s offset is exactly three 30s TOTP steps ahead of the system clock.
        Cache::put('helm.mfa.ntp_offset', 90, now()->addHour());
        $shifted = (new NtpTime())->timeStep(30);

        Cache::put('helm.mfa.ntp_offset', 0, now()->addHour());
        $system = (new NtpTime())->timeStep(30);

        $this->assertSame(3, $shifted - $system);
    }
}
