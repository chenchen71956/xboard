<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ManageController extends Controller
{
    private const WS_OUTGOING_QUEUE = 'panel_ws:outgoing';
    private const WS_RESPONSE_PREFIX = 'panel_ws:response:';

    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers()->map(function ($item) {
            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'])->get(['name', 'id']);
            $item['parent'] = $item->parent;
            return $item;
        });
        return $this->success($servers);
    }

    public function trafficQuery(Request $request)
    {
        $params = $request->validate([
            'node_id' => 'required',
            'tag' => 'nullable|string',
            'uid' => 'nullable|integer',
            'timeout' => 'nullable|integer|min:1|max:30',
        ]);

        $nodeId = (string) $params['node_id'];
        $timeout = (int) ($params['timeout'] ?? 10);

        $queryBody = [];
        if (!empty($params['tag'] ?? null)) {
            $queryBody['tag'] = $params['tag'];
        }
        if (isset($params['uid'])) {
            $queryBody['uid'] = (int) $params['uid'];
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
            'body' => json_encode($queryBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        Redis::connection()->del($responseKey);
        Redis::connection()->rpush(self::WS_OUTGOING_QUEUE, json_encode([
            'node_id' => $nodeId,
            'id' => $id,
            'ttl' => $timeout,
            'request' => $wsRequest,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $deadline = microtime(true) + $timeout;
        $respRaw = null;
        while (microtime(true) < $deadline) {
            $respRaw = Redis::connection()->get($responseKey);
            if (is_string($respRaw) && $respRaw !== '') {
                break;
            }
            usleep(100000);
        }

        if (!is_string($respRaw) || $respRaw === '') {
            return $this->fail([504, 'timeout']);
        }

        $resp = json_decode($respRaw, true);
        if (!is_array($resp)) {
            return $this->fail([500, 'invalid ws response']);
        }

        $status = (int) ($resp['status'] ?? 500);
        $bodyRaw = (string) ($resp['body'] ?? '');

        $decodedBody = null;
        if ($bodyRaw !== '') {
            $maybeDecoded = base64_decode($bodyRaw, true);
            $decodedBody = $maybeDecoded === false ? $bodyRaw : $maybeDecoded;
        }

        if ($status !== 200) {
            $message = is_string($decodedBody) && $decodedBody !== '' ? $decodedBody : 'request failed';
            return $this->fail([$status, $message]);
        }

        $payload = is_string($decodedBody) ? json_decode($decodedBody, true) : null;
        if (!is_array($payload)) {
            return $this->fail([500, 'invalid traffic payload']);
        }
        return $this->success($payload);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            try {
                $server->update($params);
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        try {
            Server::create($params);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }


    }

    public function update(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'show' => 'integer',
        ]);

        if (!Server::where('id', $request->id)->update(['show' => $request->show])) {
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        if (Server::where('id', $request->id)->delete() === false) {
            return $this->fail([500, '删除失败']);
        }
        return $this->success(true);
    }


    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        $server->show = 0;
        $server->code = null;
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        Server::create($server->toArray());
        return $this->success(true);
    }
}
