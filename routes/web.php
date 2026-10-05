<?php

use App\Enums\OrderStatus;
use App\Http\Controllers\Admin\GeneratedDocumentDownloadController;
use App\Http\Controllers\Admin\IntakeUploadDownloadController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\IntakeAnswersDownloadController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SsoController;
use App\Models\DiscountCode;
use App\Models\IntakeSubmission;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentLog;
use App\Models\Questionnaire;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public
Route::get('/', function () {
    $packages = Package::where('is_active', true)->orderBy('sort_order')->get()->keyBy('slug');

    // "Welcome back" resume banner: the client's most recent order that's past payment but not
    // yet fully approved — PendingPayment is excluded (that's "you still owe us money", a
    // different message than "continue your intake").
    $resumeOrder = auth()->check()
        ? auth()->user()->orders()
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::IntakeSubmitted, OrderStatus::UnderReview])
            ->latest()
            ->first()
        : null;

    return view('welcome', compact('packages', 'resumeOrder'));
})->name('home');
Route::get('/contact', fn () => view('contact'))->name('contact');

// Auth
Route::get('/login', fn () => view('auth.login'))->name('login');
Route::get('/register', function (Request $request) {
    if ($request->filled('package')) {
        session(['intended_package' => $request->query('package')]);
    }

    if ($request->filled('billing_cycle')) {
        session(['intended_billing_cycle' => $request->query('billing_cycle')]);
    }

    return view('auth.register');
})->name('register');
Route::get('/forgot-password', fn () => view('auth.forgot-password'))->name('password.request');
Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset-password', ['token' => $token]))->name('password.reset');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Client portal — guest-accessible: a first-time visitor creates their account as part of
// paying for a package in Step 1, matching the marketplace's combined signup + payment flow.
Route::get('/portal', fn () => view('portal'))->name('portal');

// SSO — browser-facing half of the token handoff issued by Api\SsoController::issueToken(). Lives
// here (not routes/api.php) because logging in needs the session/cookie middleware the api group
// doesn't have.
Route::get('/sso/consume/{token}', [SsoController::class, 'consume'])->name('sso.consume');

// Swagger UI for partner developers integrating the SSO API — reads public/openapi/sso.yaml.
Route::get('/api/docs', fn () => view('docs.sso-api'))->name('api.docs');

Route::middleware('auth')->group(function () {
    Route::get('/documents/{document}/download', [DocumentDownloadController::class, 'show'])
        ->name('documents.download');
    Route::get('/orders/{order}/receipt', [ReceiptController::class, 'show'])
        ->name('orders.receipt');
    Route::get('/intake-submissions/{submission}/answers', [IntakeAnswersDownloadController::class, 'show'])
        ->name('intake-submissions.answers');
    Route::get('/account/change-password', fn () => view('account.change-password'))
        ->name('password.edit');
});

// Admin panel
Route::middleware(['admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', fn () => view('admin.dashboard'))->name('dashboard');
    Route::get('/submissions', fn () => view('admin.submissions'))->name('submissions');
    Route::get('/submissions/{submission}', fn (IntakeSubmission $submission) => view('admin.submission-detail', compact('submission')))
        ->name('submissions.show');
    Route::get('/documents', fn () => view('admin.documents'))->name('documents');
    Route::get('/document-generator', fn () => view('admin.document-generator'))->name('document-generator');
    Route::get('/generated-documents/{document}/download', [GeneratedDocumentDownloadController::class, 'show'])
        ->name('generated-documents.download');
    Route::get('/leads', fn () => view('admin.leads'))->name('leads');
    Route::get('/leads/create', fn () => view('admin.leads-form'))->name('leads.create');
    Route::get('/leads/{lead}/edit', fn (Lead $lead) => view('admin.leads-form', compact('lead')))
        ->name('leads.edit');
    Route::get('/packages', fn () => view('admin.packages'))->name('packages');
    Route::get('/packages/create', fn () => view('admin.packages-form'))->name('packages.create');
    Route::get('/packages/{package}/edit', fn (Package $package) => view('admin.packages-form', compact('package')))
        ->name('packages.edit');
    Route::get('/discount-codes', fn () => view('admin.discount-codes'))->name('discount-codes');
    Route::get('/discount-codes/create', fn () => view('admin.discount-codes-form'))->name('discount-codes.create');
    Route::get('/discount-codes/{discountCode}/edit', fn (DiscountCode $discountCode) => view('admin.discount-codes-form', compact('discountCode')))
        ->name('discount-codes.edit');
    Route::get('/discount-codes/{discountCode}/send', fn (DiscountCode $discountCode) => view('admin.discount-codes-send', compact('discountCode')))
        ->name('discount-codes.send');
    Route::get('/intake-uploads/{upload}/download', [IntakeUploadDownloadController::class, 'show'])
        ->name('uploads.download');
    Route::get('/intake-questions', fn () => view('admin.intake-questions'))->name('intake-questions');
    Route::get('/questionnaires', fn () => view('admin.questionnaires'))->name('questionnaires');
    Route::get('/questionnaires/create', fn () => view('admin.questionnaires-form'))->name('questionnaires.create');
    Route::get('/questionnaires/{questionnaire}/edit', fn (Questionnaire $questionnaire) => view('admin.questionnaires-form', compact('questionnaire')))
        ->name('questionnaires.edit');
    Route::get('/users', fn () => view('admin.users'))->name('users');
    Route::get('/users/create', fn () => view('admin.users-form'))->name('users.create');
    Route::get('/users/{user}/edit', fn (User $user) => view('admin.users-form', compact('user')))
        ->name('users.edit');
    Route::get('/orders', fn () => view('admin.orders'))->name('orders');
    Route::get('/orders/{order}/edit', fn (Order $order) => view('admin.orders-form', compact('order')))
        ->name('orders.edit');
    Route::get('/activity-log', fn () => view('admin.activity-log'))->name('activity-log');
    Route::get('/payment-logs', fn () => view('admin.payment-logs'))->name('payment-logs');
    Route::get('/payment-logs/{paymentLog}', fn (PaymentLog $paymentLog) => view('admin.payment-log-detail', compact('paymentLog')))
        ->name('payment-logs.show');
});

// Any URL that doesn't match a route above (typo, stale link, etc.) — send the visitor home
// instead of showing the default 404 page.
Route::fallback(fn () => redirect()->route('home'));
