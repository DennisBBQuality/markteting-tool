<?php

use App\Http\Controllers\Api\AiSettingController;
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
use App\Http\Controllers\Api\DashboardPreferenceController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\ProductDossierController;
use App\Http\Controllers\Api\ProductDossierOptionController;
use App\Http\Controllers\Api\ProductImageController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TrunkrsReportController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureCustomerServiceEnabled;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// ========== AUTH (no middleware) ==========
Route::get('/api/auth/csrf', function () {
    return response()->noContent()->withHeaders([
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
    ]);
});
Route::post('/api/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/api/auth/logout', [AuthController::class, 'logout']);

// ========== AUTHENTICATED ROUTES ==========
Route::middleware('auth.custom')->group(function () {

    // Auth
    Route::get('/api/auth/me', [AuthController::class, 'me']);

    // Paused: retain the implementation, but also block requests from old open tabs.
    Route::middleware(EnsureCustomerServiceEnabled::class)->group(function () {
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
    });

    // Users
    Route::get('/api/users', [UserController::class, 'index']);
    Route::middleware('admin')->group(function () {
        Route::post('/api/users', [UserController::class, 'store']);
        Route::put('/api/users/{id}', [UserController::class, 'update']);
        Route::delete('/api/users/{id}', [UserController::class, 'destroy']);
        Route::get('/api/settings/ai/openai', [AiSettingController::class, 'show']);
        Route::put('/api/settings/ai/openai/image-model', [AiSettingController::class, 'updateImageModel'])->middleware('throttle:10,1');
        Route::put('/api/settings/ai/openai', [AiSettingController::class, 'update'])->middleware('throttle:5,1');
        Route::post('/api/settings/ai/openai/test', [AiSettingController::class, 'test'])->middleware('throttle:10,1');
        Route::delete('/api/settings/ai/openai', [AiSettingController::class, 'destroy']);
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

    // Product images
    Route::get('/api/images/prompt', [ProductImageController::class, 'prompt']);
    Route::get('/api/images/models', [ProductImageController::class, 'models'])->middleware('throttle:30,1');
    Route::post('/api/images/models/refresh', [ProductImageController::class, 'refreshModels'])->middleware('throttle:3,1');
    Route::put('/api/images/prompt', [ProductImageController::class, 'updatePrompt']);
    Route::post('/api/images/generate', [ProductImageController::class, 'generate'])->middleware('throttle:3,1');
    Route::get('/api/images/requests/{imageRequest}', [ProductImageController::class, 'status']);
    Route::post('/api/images/requests/{imageRequest}/assets/{asset}/refine', [ProductImageController::class, 'refine'])->middleware('throttle:6,1');
    Route::post('/api/images/requests/{imageRequest}/assets/{asset}/style-library', [ProductImageController::class, 'addToStyleLibrary'])->middleware('throttle:12,1');
    Route::get('/api/images/requests/{imageRequest}/assets/{asset}/revisions', [ProductImageController::class, 'revisions']);
    Route::post('/api/images/requests/{imageRequest}/assets/{asset}/revisions/{revision}/restore', [ProductImageController::class, 'restore']);
    // Keep the protected URL extensionless so nginx never mistakes it for a public static PNG.
    Route::get('/api/images/requests/{imageRequest}/generated/{asset}', [ProductImageController::class, 'show'])
        ->where('asset', '[A-Za-z0-9_-]+');

    // Product dossiers (uitsluitend concepten; WordPress-publicatie volgt later)
    Route::get('/api/product-dossiers/ai-status', [ProductDossierController::class, 'aiStatus']);
    Route::post('/api/images/requests/{imageRequest}/link-dossier', [ProductImageController::class, 'linkDossier']);
    Route::get('/api/product-dossiers/tone-profile', [ProductDossierController::class, 'toneProfile']);
    Route::get('/api/product-dossiers', [ProductDossierController::class, 'index']);
    Route::post('/api/product-dossiers', [ProductDossierController::class, 'store']);
    Route::get('/api/product-dossiers/{productDossier}', [ProductDossierController::class, 'show']);
    Route::put('/api/product-dossiers/{productDossier}', [ProductDossierController::class, 'update']);
    Route::get('/api/product-dossiers/{productDossier}/labels/{index}', [ProductDossierController::class, 'labelImage'])->whereNumber('index');
    Route::get('/api/product-dossiers/{productDossier}/export', [ProductDossierController::class, 'export']);
    Route::get('/api/product-dossiers/{productDossier}/expert-assets/{kind}', [ProductDossierController::class, 'expertAsset']);
    Route::post('/api/product-dossiers/{productDossier}/expert-assets/{kind}', [ProductDossierController::class, 'uploadExpertAsset'])->middleware('throttle:12,1');
    Route::post('/api/product-dossiers/{productDossier}/labels', [ProductDossierController::class, 'uploadLabels'])->middleware('throttle:12,1');
    Route::post('/api/product-dossiers/{productDossier}/analyze', [ProductDossierController::class, 'analyze'])->middleware('throttle:6,1');
    Route::post('/api/product-dossiers/{productDossier}/estimate-nutrition', [ProductDossierController::class, 'estimateNutrition'])->middleware('throttle:6,1');
    Route::post('/api/product-dossiers/{productDossier}/generate-page', [ProductDossierController::class, 'generatePage'])->middleware('throttle:10,1');
    Route::get('/api/product-dossier-options', [ProductDossierOptionController::class, 'index']);
    Route::post('/api/product-dossier-options', [ProductDossierOptionController::class, 'store']);
    Route::delete('/api/product-dossier-options/{productDossierOption}', [ProductDossierOptionController::class, 'destroy']);

    // Dashboard
    Route::get('/api/trunkrs/summary', [TrunkrsReportController::class, 'summary']);
    Route::get('/api/trunkrs/reports', [TrunkrsReportController::class, 'index']);
    Route::get('/api/trunkrs/reports/{id}', [TrunkrsReportController::class, 'show'])->whereUuid('id');
    Route::get('/api/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/api/dashboard/preferences', [DashboardPreferenceController::class, 'show']);
    Route::put('/api/dashboard/preferences', [DashboardPreferenceController::class, 'update']);

    // Serve uploaded files
    Route::get('/uploads/{filename}', function (string $filename) {
        $safeFilename = basename($filename);
        $relativePath = 'uploads/'.$safeFilename;

        if (! Storage::disk('local')->exists($relativePath)) {
            abort(404);
        }

        $headers = ['X-Content-Type-Options' => 'nosniff'];
        $inlineExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (! in_array(strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION)), $inlineExtensions, true)) {
            $headers['Content-Disposition'] = 'attachment; filename="'.addcslashes($safeFilename, '"\\').'"';
        }

        return response()->file(Storage::disk('local')->path($relativePath), $headers);
    });
});

// Unknown API endpoints must never fall through to the HTML app shell.
Route::get('/api/{any}', fn () => response()->json([
    'error' => 'Deze API-route bestaat niet.',
], 404))->where('any', '.*');

// SPA fallback - serve index.html for all non-API routes
Route::get('/{any?}', function () {
    return response()->file(public_path('index.html'), [
        'Cache-Control' => 'no-cache, must-revalidate',
    ]);
})->where('any', '.*');
