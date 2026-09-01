<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\ConvertController;
use App\Http\Controllers\Api\CustomerService\TicketActivityController;
use App\Http\Controllers\Api\CustomerService\TicketClaimController;
use App\Http\Controllers\Api\CustomerService\TicketController;
use App\Http\Controllers\Api\CustomerService\TicketMessageController;
use App\Http\Controllers\Api\CustomerService\TicketNoteController;
use App\Http\Controllers\Api\CustomerService\TicketPriorityController;
use App\Http\Controllers\Api\CustomerService\TicketStatusController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// ========== AUTH (no middleware) ==========
Route::post('/api/auth/login', [AuthController::class, 'login']);
Route::post('/api/auth/logout', [AuthController::class, 'logout']);

// ========== AUTHENTICATED ROUTES ==========
Route::middleware('auth.custom')->group(function () {

    // Auth
    Route::get('/api/auth/me', [AuthController::class, 'me']);

    // Customer Service
    Route::get('/api/customer-service/tickets', [TicketController::class, 'index']);
    Route::post('/api/customer-service/tickets', [TicketController::class, 'store']);
    Route::get('/api/customer-service/tickets/{id}', [TicketController::class, 'show']);
    Route::post('/api/customer-service/tickets/{id}/claim', [TicketClaimController::class, 'claim']);
    Route::post('/api/customer-service/tickets/{id}/release', [TicketClaimController::class, 'release']);
    Route::put('/api/customer-service/tickets/{id}/status', [TicketStatusController::class, 'update']);
    Route::put('/api/customer-service/tickets/{id}/priority', [TicketPriorityController::class, 'update']);
    Route::get('/api/customer-service/tickets/{id}/messages', [TicketMessageController::class, 'index']);
    Route::post('/api/customer-service/tickets/{id}/messages', [TicketMessageController::class, 'store']);
    Route::get('/api/customer-service/tickets/{id}/notes', [TicketNoteController::class, 'index']);
    Route::post('/api/customer-service/tickets/{id}/notes', [TicketNoteController::class, 'store']);
    Route::get('/api/customer-service/tickets/{id}/activities', [TicketActivityController::class, 'index']);

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

    // Serve uploaded files
    Route::get('/uploads/{filename}', function (string $filename) {
        $path = storage_path('app/public/uploads/'.basename($filename));
        if (! file_exists($path)) {
            abort(404);
        }

        return response()->file($path);
    });
});

// SPA fallback - serve index.html for all non-API routes
Route::get('/{any?}', function () {
    return response()->file(public_path('index.html'));
})->where('any', '.*');
