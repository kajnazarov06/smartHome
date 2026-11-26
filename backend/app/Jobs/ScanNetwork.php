<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Devices\Discovery\DiscoveredDevice;
use App\Devices\Discovery\DiscoveryService;
use App\Events\DiscoveryFinished;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a discovery scan on the queue worker, which (unlike php-fpm) runs with host
 * networking and can therefore receive mDNS answers.
 */
class ScanNetwork implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $uniqueFor = 30;

    public function __construct(
        public readonly int $userId,
        public readonly ?string $subnet = null,
    ) {}

    public function handle(DiscoveryService $discovery): void
    {
        $discovery->scan($this->subnet);

        DiscoveryFinished::dispatch(
            $this->userId,
            array_map(fn (DiscoveredDevice $d) => $d->toArray(), $discovery->unpaired()),
        );
    }
}
