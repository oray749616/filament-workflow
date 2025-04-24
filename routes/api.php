<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DeepSeekController;

// 其他需要认证的路由
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});

// 公开路由 - 无需认证
Route::post('/deepseek/chat', [DeepSeekController::class, 'chat']);
Route::post('/deepseek/streamChat', [DeepSeekController::class, 'streamChat']);