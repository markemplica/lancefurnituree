<?php

use App\Http\Controllers\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\ReservationController as AdminReservationController;
use App\Http\Controllers\Admin\RoomPlacementEvaluationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\RecommendationFeedbackController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomVisualizationController;
use App\Http\Controllers\RoomPreviewV2Controller;
use App\Http\Controllers\RoomPhotoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('home');
Route::get('/health', HealthController::class)->name('health')->middleware('throttle:30,1');
Route::get('/feedback', [App\Http\Controllers\FeedbackController::class, 'create'])->name('feedback.create');
Route::post('/feedback', [App\Http\Controllers\FeedbackController::class, 'store'])->middleware('throttle:3,1')->name('feedback.store')->middleware('throttle:email-requests');
Route::view('/how-it-works', 'how-it-works')->name('how-it-works');
Route::view('/privacy-policy', 'privacy-policy')->name('privacy-policy');
Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/search', [CatalogController::class, 'search'])->name('catalog.search')->middleware('throttle:catalog-search');
Route::get('/catalog/{furnitureProduct}', [CatalogController::class, 'show'])->name('catalog.show');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store')->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store')->middleware('throttle:5,1')->middleware('throttle:email-requests');
    Route::get('/verify-email', [AuthController::class, 'showVerification'])->name('verification.notice');
    Route::post('/verify-email', [AuthController::class, 'verifyEmail'])->name('verification.verify')->middleware('throttle:8,1');
    Route::post('/verify-email/resend', [AuthController::class, 'resendVerificationCode'])->name('verification.send')->middleware('throttle:3,1')->middleware('throttle:email-requests');
    Route::get('/forgot-password', [PasswordResetController::class, 'showRequest'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendCode'])->name('password.email')->middleware('throttle:3,1')->middleware('throttle:email-requests');
    Route::get('/reset-password', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password/resend', [PasswordResetController::class, 'resendCode'])->name('password.resend')->middleware('throttle:3,1')->middleware('throttle:email-requests');
    Route::post('/reset-password/verify', [PasswordResetController::class, 'verifyCode'])->name('password.verify')->middleware('throttle:8,1');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update')->middleware('throttle:8,1');
});
Route::middleware('auth')->group(function () {
    Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/room-images/{roomImage}', [RoomPhotoController::class, 'original'])->name('room-images.original');
    Route::get('/room-images/{roomImage}/thumbnail', [RoomPhotoController::class, 'thumbnail'])->name('room-images.thumbnail');
    Route::get('/room-images/{roomImage}/previews/{product}', [RoomPhotoController::class, 'preview'])->whereNumber('product')->name('room-images.preview');

    Route::middleware('role:customer')->group(function () {
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update')->middleware('throttle:profile-updates');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password')->middleware('throttle:password-changes');
        Route::delete('/profile/account', [ProfileController::class, 'destroy'])->name('profile.destroy')->middleware('throttle:account-deletions');
        Route::get('/favorites', [CatalogController::class, 'favorites'])->name('favorites.index');
        Route::post('/catalog/{furnitureProduct}/favorite', [CatalogController::class, 'favorite'])->name('catalog.favorite');
        Route::get('/preferences', [PreferenceController::class, 'edit'])->name('preferences.edit');
        Route::post('/preferences', [PreferenceController::class, 'update'])->name('preferences.update');
        Route::post('/recommendations', [RecommendationController::class, 'store'])->name('recommendations.store')->middleware('throttle:recommendation-generation');
        Route::get('/recommendations', [RecommendationController::class, 'index'])->name('recommendations.index');
        Route::get('/recommendations/{recommendation}', [RecommendationController::class, 'show'])->name('recommendations.show');
        Route::post('/recommendations/{recommendation}/items/{recommendationItem}/feedback', [RecommendationFeedbackController::class, 'update'])->name('recommendations.items.feedback');
        Route::post('/recommendations/{recommendation}/chat', [ChatController::class, 'store'])->name('recommendations.chat')->middleware('throttle:chatbot-messages');
        Route::get('/room', [RoomController::class, 'index'])->name('room.index');
        Route::post('/room', [RoomController::class, 'store'])->name('room.store')->middleware('throttle:room-uploads');
        Route::delete('/room/{roomImage}', [RoomController::class, 'destroy'])->name('room.destroy');
        Route::delete('/room/{roomImage}/ai-consent', [RoomController::class, 'revokeAiConsent'])->name('room.ai-consent.revoke');
        Route::get('/room/{roomImage}/visualize', [RoomVisualizationController::class, 'visualize'])->name('room.visualize');
        Route::get('/room/{roomImage}/analysis-status', [RoomController::class, 'analysisStatus'])->name('room.analysis-status')->middleware('throttle:ai-status');
        Route::post('/room/{roomImage}/preview-verify/{product:id}', [RoomVisualizationController::class, 'verifyPreview'])->withoutScopedBindings()->whereNumber('product')->name('room.preview-verify')->middleware('throttle:ai-room-actions');
        Route::get('/room/{roomImage}/preview-status/{product}', [RoomVisualizationController::class, 'previewStatus'])->whereNumber('product')->name('room.preview-status')->middleware('throttle:ai-status');
        Route::post('/room/{roomImage}/reanalyze', [RoomController::class, 'reanalyze'])->name('room.reanalyze')->middleware('throttle:ai-room-actions');
        Route::post('/room/{roomImage}/preview-v2', [RoomPreviewV2Controller::class, 'store'])->name('room.preview-v2.store')->middleware('throttle:ai-room-actions');
        Route::get('/room/{roomImage}/preview-v2/{generation}', [RoomPreviewV2Controller::class, 'status'])->whereNumber('generation')->name('room.preview-v2.status')->middleware('throttle:60,1');
        Route::get('/room-preview-v2/artifacts/{generation}', [RoomPreviewV2Controller::class, 'artifact'])->whereNumber('generation')->middleware('signed')->name('room.preview-v2.artifact');

        Route::post('/reservations', [ReservationController::class, 'store'])->name('reservations.store')->middleware('throttle:reservation-requests');
        Route::patch('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel')->middleware('throttle:reservation-cancellations');
        Route::delete('/reservations/{reservation}', [ReservationController::class, 'destroy'])->name('reservations.destroy')->middleware('throttle:reservation-cancellations');
    });

    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/api-usage', [AdminDashboardController::class, 'apiUsage'])->name('api-usage');
        Route::get('/room-placement-evaluation', [RoomPlacementEvaluationController::class, 'index'])->name('room-placement-evaluation.index');
        Route::post('/room-placement-evaluation/{roomImage}', [RoomPlacementEvaluationController::class, 'store'])->name('room-placement-evaluation.store');
        Route::get('/room-placement-evaluation/{roomImage}/preview', [RoomVisualizationController::class, 'visualize'])->name('room-placement-evaluation.preview');
        Route::resource('products', AdminProductController::class)->except('show');
        Route::patch('/products/{product}/status', [AdminProductController::class, 'updateStatus'])->name('products.status');
        Route::delete('/products/{product}/permanently', [AdminProductController::class, 'destroyPermanently'])->name('products.destroy-permanently');
        Route::get('/customers', [AdminCustomerController::class, 'index'])->name('customers.index');
        Route::patch('/customers/{user}', [AdminCustomerController::class, 'update'])->name('customers.update');
        Route::get('/reservations', [AdminReservationController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/{reservation}/preview', [App\Http\Controllers\Admin\ReservationPreviewController::class, 'show'])->name('reservations.preview');
        Route::get('/reservations/{reservation}/preview/image', [App\Http\Controllers\Admin\ReservationPreviewController::class, 'image'])->name('reservations.preview-image');
        Route::get('/reports', [App\Http\Controllers\Admin\ReportsController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [App\Http\Controllers\Admin\ReportsController::class, 'export'])->name('reports.export');
        Route::get('/privacy-audit', [App\Http\Controllers\Admin\PrivacyAuditController::class, 'index'])->name('privacy-audit');
        Route::patch('/reservations/{reservation}', [AdminReservationController::class, 'update'])->name('reservations.update');
    });
});
