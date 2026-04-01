<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class PullTrafficFromNodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const WS_OUTGOING_QUEUE = 'panel_ws:outgoing';
    private const WS_RESPONSE_PREFIX = 'panel_ws:response:';

    protected string $nodeId;
    protected int $uid;

    public $tries = 1;
    public $timeout = 8;

    public function __construct(string $nodeId, int $uid)
    {
        $this->onQueue('traffic_fetch');
        $this->nodeId = $nodeId;
        $this->uid = $uid;
    }

    public function handle(): void
    {
        if ($this->uid <= 0 || $this->nodeId === '') {
            return;
        }

        $failCountKey = CacheKey::get('REALTIME_PULL_FAIL_COUNT', $this->uid . '_' . $this->nodeId);

        $server = ServerService::getServer($this->nodeId, null);
        if (!$server instanceof Server) {
            Cache::put($failCountKey, ((int) Cache::get($failCountKey, 0)) + 1, 600);
            return;
        }

        $id = (string) ((int) floor(microtime(true) * 1000)) . '_' . bin2hex(random_bytes(4));
        $responseKey = self::WS_RESPONSE_PREFIX . $id;

        $wsRequest = [
            'id' => $id,
            'method' => 'POST',
            'path' => '/api/v1/server/UniProxy/traffic/query',
            'headers' => [
                'Content-Type' => ['application/json'],
            ],
            'body' => json_encode([
                'uid' => $this->uid
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        Redis::connection()->del($responseKey);
        Redis::connection()->rpush(self::WS_OUTGOING_QUEUE, json_encode([
            'node_id' => $this->nodeId,
            'id' => $id,
            'ttl' => 5,
            'request' => $wsRequest,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $deadline = microtime(true) + 5;
        $respRaw = null;
        while (microtime(true) < $deadline) {
            $respRaw = Redis::connection()->get($responseKey);
            if (is_string($respRaw) && $respRaw !== '') {
                break;
            }
            usleep(50000);
        }

        if (!is_string($respRaw) || $respRaw === '') {
            Cache::put($failCountKey, ((int) Cache::get($failCountKey, 0)) + 1, 600);
            return;
        }

        $resp = json_decode($respRaw, true);
        if (!is_array($resp)) {
            Cache::put($failCountKey, ((int) Cache::get($failCountKey, 0)) + 1, 600);
            return;
        }

        $status = (int) ($resp['status'] ?? 500);
        if ($status !== 200) {
            Cache::put($failCountKey, ((int) Cache::get($failCountKey, 0)) + 1, 600);
            return;
        }

        $bodyRaw = (string) ($resp['body'] ?? '');
        $decodedBody = '';
        if ($bodyRaw !== '') {
            $maybeDecoded = base64_decode($bodyRaw, true);
            $decodedBody = $maybeDecoded === false ? $bodyRaw : $maybeDecoded;
        }

        $payload = $decodedBody !== '' ? json_decode($decodedBody, true) : null;
        if (!is_array($payload)) {
            Cache::put($failCountKey, ((int) Cache::get($failCountKey, 0)) + 1, 600);
            return;
        }

        $traffic = $payload['traffic'] ?? null;
        if (!is_array($traffic)) {
            Cache::put($failCountKey, ((int) Cache::get($failCountKey, 0)) + 1, 600);
            return;
        }

        $data = [];
        foreach ($traffic as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid = (int) ($row['UID'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $u = (int) ($row['Upload'] ?? 0);
            $d = (int) ($row['Download'] ?? 0);
            $data[$uid] = [$u, $d];
        }

        if (empty($data)) {
            Cache::put($failCountKey, ((int) Cache::get($failCountKey, 0)) + 1, 600);
            return;
        }

        $userService = new UserService();
        $userService->trafficFetch($server, $server->type, $data);

        Cache::forget($failCountKey);
    }
}
