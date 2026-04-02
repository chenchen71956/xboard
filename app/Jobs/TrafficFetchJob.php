<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    public int $tries = 3;
    public int $timeout = 30;

    public function backoff(): array
    {
        return [1, 5, 10];
    }

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $server, array $data, $protocol, int $timestamp)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
    }

    public function handle(): void
    {
        $rate = (float) ($this->server['rate'] ?? 1);

        foreach ($this->data as $uid => $v) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }

            if (!is_array($v) || !isset($v[0], $v[1]) || !is_numeric($v[0]) || !is_numeric($v[1])) {
                continue;
            }

            $u = (float) $v[0] * $rate;
            $d = (float) $v[1] * $rate;
            if ($u <= 0 && $d <= 0) {
                continue;
            }

            User::where('id', $uid)
                ->incrementEach(
                    [
                        'u' => $u,
                        'd' => $d,
                    ],
                    ['t' => time()]
                );
        }
    }
}
