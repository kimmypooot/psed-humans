<?php

use App\Http\Controllers\ActionOfficerController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\ImisSsoController;
use App\Http\Controllers\Auth\ImisSsoLogoutController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\SessionHeartbeatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MechanismController;
use App\Http\Controllers\MechanismWorkflowController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Public\SubmissionController;
use App\Http\Controllers\Public\TrackingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TransmittalController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// --- Public: agencies have no accounts --------------------------------------------------------
// Anyone may submit an HR mechanism and follow it. The submit form leaves an email address; the
// tracking page is reached by a signed link (emailed) or by giving the tracking code and that address.
Route::get('/', [SubmissionController::class, 'create'])->name('home');
Route::get('/submit', fn () => redirect()->route('home'));
Route::post('/submit', [SubmissionController::class, 'store'])->middleware('throttle:6,10')->name('submit.store');

Route::get('/track', [TrackingController::class, 'lookup'])->name('track');
Route::post('/track', [TrackingController::class, 'find'])->middleware('throttle:10,1')->name('track.find');
Route::get('/track/{mechanism}', [TrackingController::class, 'show'])->whereNumber('mechanism')->middleware('signed')->name('track.show');
Route::post('/track/{mechanism}/resubmit', [TrackingController::class, 'resubmit'])->whereNumber('mechanism')->middleware(['signed', 'throttle:6,10'])->name('track.resubmit');

// --- Staff: PSED, the Director IV and the Field Offices, signed in from IMIS ------------------
// There is no password sign-in: staff open this system from IMIS (see ImisSsoController).
Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::get('/sso', [ImisSsoController::class, 'show'])->middleware('throttle:30,1')->name('sso');
    Route::post('/sso', [ImisSsoController::class, 'store'])->middleware('throttle:30,1')->name('sso.store');
});

// IMIS tells us a person signed out of IMIS (single sign-out). Server-to-server: no cookie and
// no CSRF token, the secret `sid` is the only credential. Outside the guest/auth groups on purpose.
Route::post('/sso/logout', ImisSsoLogoutController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('sso.logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    // Idle-timeout keep-alive; account-level like logout.
    Route::post('/session/heartbeat', SessionHeartbeatController::class)->middleware('throttle:30,1')->name('session.heartbeat');

    // The bell in the header: the person's own notifications.
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('throttle:120,1')->name('notifications.index');
    Route::get('/notifications/all', [NotificationController::class, 'page'])->name('notifications.page');
    Route::post('/notifications/clear-read', [NotificationController::class, 'clearRead'])->middleware('throttle:30,1')->name('notifications.clear-read');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('throttle:30,1')->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->middleware('throttle:60,1')->name('notifications.read');

    // HR mechanisms. What each person sees is HrMechanism::visibleTo; what they may do is HrMechanismPolicy.
    // `show` answers the modal with JSON; there is no page for a single mechanism.
    Route::get('/mechanisms', [MechanismController::class, 'index'])->name('mechanisms.index');
    Route::get('/mechanisms/{mechanism}', [MechanismController::class, 'show'])->whereNumber('mechanism')->name('mechanisms.show');
    Route::get('/mechanisms/{mechanism}/file', [MechanismController::class, 'file'])->whereNumber('mechanism')->middleware('throttle:60,1')->name('mechanisms.file');
    Route::get('/mechanisms/{mechanism}/attachments/{kind}', [MechanismController::class, 'attachment'])->whereNumber('mechanism')->middleware('throttle:60,1')->name('mechanisms.attachment');

    // Analytics and reports: read-only, and limited to what the person may see (HrMechanism::visibleTo).
    Route::get('/analytics', AnalyticsController::class)->name('analytics');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->middleware('throttle:10,1')->name('reports.export');

    // PSED staff name a set of initials from the old workbook that nobody has identified yet.
    Route::get('/action-officers/directory', [ActionOfficerController::class, 'directory'])->middleware('throttle:30,1')->name('action-officers.directory');
    Route::patch('/action-officers/{officer}', [ActionOfficerController::class, 'update'])->whereNumber('officer')->middleware('throttle:30,1')->name('action-officers.update');

    // The transmittal letter before it is sent: its starting values, and a draft to read. `type` is approval or compliance.
    Route::get('/mechanisms/{mechanism}/transmittal/{type}', [TransmittalController::class, 'show'])->whereNumber('mechanism')->whereIn('type', ['approval', 'compliance'])->name('mechanisms.transmittal');
    Route::post('/mechanisms/{mechanism}/transmittal/{type}/view', [TransmittalController::class, 'view'])->whereNumber('mechanism')->whereIn('type', ['approval', 'compliance'])->middleware('throttle:60,1')->name('mechanisms.transmittal.view');
    Route::post('/mechanisms/{mechanism}/transmittal/{type}/preview', [TransmittalController::class, 'preview'])->whereNumber('mechanism')->whereIn('type', ['approval', 'compliance'])->middleware('throttle:30,1')->name('mechanisms.transmittal.preview');

    // The process: route -> acknowledge (20-day clock) -> forward (with the three documents) -> Chief approves
    // -> final approval; or For Compliance, with the three documents emailed to the agency.
    Route::post('/mechanisms/{mechanism}/route', [MechanismWorkflowController::class, 'route'])->whereNumber('mechanism')->name('mechanisms.route');
    Route::post('/mechanisms/{mechanism}/acknowledge', [MechanismWorkflowController::class, 'acknowledge'])->whereNumber('mechanism')->name('mechanisms.acknowledge');
    Route::post('/mechanisms/{mechanism}/forward', [MechanismWorkflowController::class, 'forward'])->whereNumber('mechanism')->middleware('throttle:30,1')->name('mechanisms.forward');
    Route::post('/mechanisms/{mechanism}/compliance', [MechanismWorkflowController::class, 'returnForCompliance'])->whereNumber('mechanism')->middleware('throttle:30,1')->name('mechanisms.compliance');
    Route::post('/mechanisms/{mechanism}/return-to-officer', [MechanismWorkflowController::class, 'returnToOfficer'])->whereNumber('mechanism')->middleware('throttle:30,1')->name('mechanisms.return-to-officer');
    Route::post('/mechanisms/{mechanism}/generate-transmittal', [MechanismWorkflowController::class, 'generateTransmittal'])->whereNumber('mechanism')->middleware('throttle:30,1')->name('mechanisms.generate-transmittal');
    Route::post('/mechanisms/{mechanism}/transmit', [MechanismWorkflowController::class, 'transmit'])->whereNumber('mechanism')->middleware('throttle:30,1')->name('mechanisms.transmit');
    Route::post('/mechanisms/{mechanism}/distribute', [MechanismWorkflowController::class, 'distribute'])->whereNumber('mechanism')->name('mechanisms.distribute');
    Route::post('/mechanisms/{mechanism}/chief-approve', [MechanismWorkflowController::class, 'chiefApprove'])->whereNumber('mechanism')->name('mechanisms.chief-approve');
    Route::post('/mechanisms/{mechanism}/final-approve', [MechanismWorkflowController::class, 'finalApprove'])->whereNumber('mechanism')->name('mechanisms.final-approve');
});
