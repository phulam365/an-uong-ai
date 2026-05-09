<?php

use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\ChatSessionController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderSuccessController;
use Illuminate\Support\Facades\Route;

Route::get('/', MenuController::class)->name('home');
Route::post('/chat/session', ChatSessionController::class)->name('chat.session');
Route::post('/chat/messages', [ChatMessageController::class, 'store'])->name('chat.messages.store');
Route::get('/chat/messages/{turn}', [ChatMessageController::class, 'show'])->name('chat.messages.show');
Route::get('/order/success', OrderSuccessController::class)->name('order.success');
