<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function (Request $request) {
    $html = '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8" />'
        . '<meta name="viewport" content="width=device-width,initial-scale=1" />'
        . '<title>' . e('Coming Soon') . '</title>'
        . '<style>'
        . 'html,body{height:100%;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;}'
        . 'body{display:flex;align-items:center;justify-content:center;background:#0b1220;color:#e5e7eb;}'
        . '.wrap{text-align:center;max-width:720px;padding:24px;}'
        . 'h1{margin:0 0 12px;font-size:42px;letter-spacing:.02em;}'
        . 'p{margin:0;color:#9ca3af;font-size:16px;}'
        . '</style>'
        . '</head><body><div class="wrap">'
        . '<h1>Coming Soon</h1>'
        . '<p>' . e('Coming Soon') . '</p>'
        . '</div></body></html>';

    return response($html)
        ->header('content-type', 'text/html; charset=UTF-8');
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');