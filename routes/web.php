<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\ConvertController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\NotificationController;

// ========== AUTH (no middleware) ==========
Route::post('/api/auth/login', [AuthController::class, 'login']);
Route::post('/api/auth/logout', [AuthController::class, 'logout']);

// ========== AUTHENTICATED ROUTES ==========
Route::middleware('auth.custom')->group(function () {

    // Auth
    Route::get('/api/auth/me', [AuthController::class, 'me']);

    // Users
    Route::get('/api/users', [UserController::class, 'index']);
    Route::middleware('admin')->group(function () {
        Route::post('/api/users', [UserController::class, 'store']);
        Route::put('/api/users/{id}', [UserController::class, 'update']);
        Route::delete('/api/users/{id}', [UserController::class, 'destroy']);
    });

    // Projects
    Route::get('/api/projects', [ProjectController::class, 'index']);
    Route::post('/api/projects', [ProjectController::class, 'store']);
    Route::put('/api/projects/{id}', [ProjectController::class, 'update']);
    Route::delete('/api/projects/{id}', [ProjectController::class, 'destroy'])->middleware('manager_or_admin');

    // Tasks
    Route::get('/api/tasks', [TaskController::class, 'index']);
    Route::post('/api/tasks', [TaskController::class, 'store']);
    Route::put('/api/tasks/reorder/batch', [TaskController::class, 'reorderBatch']);
    Route::put('/api/tasks/{id}', [TaskController::class, 'update']);
    Route::delete('/api/tasks/{id}', [TaskController::class, 'destroy']);

    // Calendar
    Route::get('/api/calendar', [CalendarController::class, 'index']);
    Route::post('/api/calendar', [CalendarController::class, 'store']);
    Route::put('/api/calendar/{id}', [CalendarController::class, 'update']);
    Route::delete('/api/calendar/{id}', [CalendarController::class, 'destroy']);

    // Notes
    Route::get('/api/notes', [NoteController::class, 'index']);
    Route::post('/api/notes', [NoteController::class, 'store']);
    Route::put('/api/notes/{id}', [NoteController::class, 'update']);
    Route::delete('/api/notes/{id}', [NoteController::class, 'destroy']);

    // Attachments
    Route::get('/api/attachments', [AttachmentController::class, 'index']);
    Route::post('/api/attachments', [AttachmentController::class, 'store']);
    Route::delete('/api/attachments/{id}', [AttachmentController::class, 'destroy']);

    // Image Converter
    Route::post('/api/convert/webp', [ConvertController::class, 'toWebp']);
    Route::get('/api/convert/download/{filename}', [ConvertController::class, 'download']);

    // Dashboard
    Route::get('/api/dashboard/stats', [DashboardController::class, 'stats']);

    // Chat Channels
    Route::get('/api/chat/channels', [ChatController::class, 'channels']);
    Route::get('/api/chat/channels/{id}', [ChatController::class, 'channel']);
    Route::post('/api/chat/channels', [ChatController::class, 'createChannel'])->middleware('manager_or_admin');
    Route::delete('/api/chat/channels/{id}', [ChatController::class, 'deleteChannel'])->middleware('admin');

    // Chat Messages
    Route::get('/api/chat/channels/{id}/messages', [ChatController::class, 'channelMessages']);
    Route::post('/api/chat/channels/{id}/messages', [ChatController::class, 'sendChannelMessage']);
    Route::delete('/api/chat/messages/{id}', [ChatController::class, 'deleteMessage']);

    // Direct Messages
    Route::get('/api/chat/direct', [ChatController::class, 'directThreads']);
    Route::get('/api/chat/direct/{userId}', [ChatController::class, 'directThread']);
    Route::get('/api/chat/threads/{threadId}/messages', [ChatController::class, 'threadMessages']);
    Route::post('/api/chat/threads/{threadId}/messages', [ChatController::class, 'sendThreadMessage']);

    // Notifications
    Route::get('/api/notifications', [NotificationController::class, 'index']);
    Route::get('/api/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::put('/api/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::put('/api/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::put('/api/notifications/read-channel/{channelId}', [NotificationController::class, 'markChannelRead']);
    Route::put('/api/notifications/read-thread/{threadId}', [NotificationController::class, 'markThreadRead']);

    // Chat Polling
    Route::get('/api/chat/poll', [ChatController::class, 'poll']);

    // Serve uploaded files
    Route::get('/uploads/{filename}', function (string $filename) {
        $path = storage_path('app/public/uploads/' . basename($filename));
        if (!file_exists($path)) abort(404);
        return response()->file($path);
    });
});

// SPA fallback - serve index.html for all non-API routes
Route::get('/{any?}', function () {
    return response()->file(public_path('index.html'));
})->where('any', '.*');
