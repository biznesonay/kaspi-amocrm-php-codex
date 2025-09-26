<?php
declare(strict_types=1);

final class RateLimiter {
    private float $last = 0.0;
    private float $perSecond;
    public function __construct(float $perSecond = 7.0) {
        $this->perSecond = $perSecond;
    }
    public function throttle(): void {
        $now = microtime(true);
        $minInterval = 1.0 / $this->perSecond;
        $diff = $now - $this->last;
        if ($diff < $minInterval) {
            usleep((int)(($minInterval - $diff)*1_000_000));
        }
        $this->last = microtime(true);
    }
}
