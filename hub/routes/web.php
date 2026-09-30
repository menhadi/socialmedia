<?php

use App\Http\Controllers\AiGenerationController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApplicationIntakeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\CardPlanController;
use App\Http\Controllers\FacebookWebhookController;
use App\Http\Controllers\HubController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\PublicationDeletionController;
use App\Http\Controllers\PublishingAssetController;
use App\Http\Controllers\ResearchController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\XAuthorizationController;
use Illuminate\Support\Facades\Route;

Route::get('/publishing-assets/{publication}', PublishingAssetController::class)->middleware(['signed', 'throttle:120,1'])->name('publishing.asset');

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::get('/social-accounts/x/callback', [XAuthorizationController::class, 'callback'])->middleware('throttle:10,1')->name('social.x.callback');
    Route::post('/social-accounts/{account}/x/connect', [XAuthorizationController::class, 'connect'])->middleware('throttle:10,1')->name('social.x.connect');
    Route::post('/posts/{post}/plan-cards', CardPlanController::class)->middleware('throttle:5,1')->name('posts.cards.plan');
    Route::get('/publications/{publication}/delete', [PublicationDeletionController::class, 'preview'])->name('publications.delete.preview');
    Route::post('/publications/{publication}/delete', [PublicationDeletionController::class, 'destroy'])->middleware('throttle:5,1')->name('publications.delete');
    Route::post('/publications/{publication}/external-removal', [PublicationDeletionController::class, 'external'])->middleware('throttle:5,1')->name('publications.external-removal');

    Route::get('/automation', [AutomationController::class, 'index'])->name('automation');
    Route::post('/automation/rules', [AutomationController::class, 'save'])->name('automation.save');
    Route::post('/automation/content', [ApplicationIntakeController::class, 'manual'])->name('automation.content');
    Route::post('/automation/content/{item}/approve', [AutomationController::class, 'approve'])->name('automation.approve');
    Route::post('/automation/{brand}/token', [ApplicationIntakeController::class, 'token'])->name('automation.token');
    Route::post('/posts/{post}/assess', [AutomationController::class, 'assess'])->middleware('throttle:5,1')->name('posts.assess');
    Route::post('/posts/{post}/archive', [AutomationController::class, 'archive'])->name('posts.archive');
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
    Route::post('/analytics/{publication}/refresh', [AnalyticsController::class, 'refresh'])->middleware('throttle:5,1')->name('analytics.refresh');

    Route::get('/media-providers', [MediaController::class, 'settings'])->name('media.settings');
    Route::put('/media-providers/{kind}', [MediaController::class, 'saveSettings'])->name('media.settings.save');
    Route::get('/posts/{post}/media', [MediaController::class, 'index'])->name('media');
    Route::post('/posts/{post}/media', [MediaController::class, 'store'])->middleware('throttle:5,1')->name('media.store');
    Route::get('/media/{generation}/file', [MediaController::class, 'file'])->name('media.file');
    Route::post('/media/{generation}/attach', [MediaController::class, 'attach'])->name('media.attach');
    Route::get('/posts/{post}/video', [MediaController::class, 'video'])->name('posts.video');
    Route::get('/research', [ResearchController::class, 'index'])->name('research');
    Route::post('/research', [ResearchController::class, 'save'])->name('research.store');
    Route::put('/research/{source}', [ResearchController::class, 'save'])->name('research.update');
    Route::post('/research/{source}/check', [ResearchController::class, 'check'])->middleware('throttle:5,1')->name('research.check');
    Route::post('/research/brands/{brand}/website', [ResearchController::class, 'website'])->middleware('throttle:5,1')->name('research.website');
    Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules');
    Route::post('/posts/{post}/schedule', [ScheduleController::class, 'store'])->name('schedules.store');
    Route::post('/schedules/{schedule}/cancel', [ScheduleController::class, 'cancel'])->name('schedules.cancel');
    Route::get('/posts/{post}/image', [ScheduleController::class, 'image'])->name('posts.image');
    Route::post('/posts/{post}/image', [ScheduleController::class, 'createImage'])->middleware('throttle:5,1')->name('posts.image.create');
    Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring');
    Route::put('/monitoring/applications/{brand}', [MonitoringController::class, 'save'])->name('monitoring.save');
    Route::get('/monitoring/{monitor}', [MonitoringController::class, 'show'])->name('monitoring.show');
    Route::post('/monitoring/{monitor}/check', [MonitoringController::class, 'check'])->middleware('throttle:10,1')->name('monitoring.check');
    Route::get('/social-accounts', [SocialAccountController::class, 'index'])->name('social');
    Route::post('/social-accounts', [SocialAccountController::class, 'store'])->name('social.store');
    Route::put('/social-accounts/{account}', [SocialAccountController::class, 'update'])->name('social.update');
    Route::post('/social-accounts/{account}/verify', [SocialAccountController::class, 'verify'])->middleware('throttle:10,1')->name('social.verify');
    Route::delete('/social-accounts/{account}', [SocialAccountController::class, 'disconnect'])->name('social.disconnect');
    Route::get('/posts/{post}/publish', [PublicationController::class, 'preview'])->name('posts.publish');
    Route::post('/posts/{post}/publish', [PublicationController::class, 'publish'])->middleware('throttle:10,1')->name('posts.publish.store');
    Route::post('/publications/{publication}/link', [PublicationController::class, 'refreshLink'])->middleware('throttle:10,1')->name('publications.link');
    Route::get('/ai-assistant', [AiGenerationController::class, 'index'])->name('ai');
    Route::post('/ai-assistant', [AiGenerationController::class, 'generate'])->middleware('throttle:10,1')->name('ai.generate');
    Route::get('/ai-assistant/{generation}', [AiGenerationController::class, 'show'])->name('ai.show');
    Route::post('/ai-assistant/{generation}/draft', [AiGenerationController::class, 'save'])->name('ai.save');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [HubController::class, 'dashboard'])->name('dashboard');
    Route::get('/applications', [HubController::class, 'applications'])->name('applications');
    Route::get('/applications/new', [HubController::class, 'brandForm'])->name('applications.create');
    Route::post('/applications', [HubController::class, 'saveBrand'])->name('applications.store');
    Route::get('/applications/{brand}/edit', [HubController::class, 'brandForm'])->name('applications.edit');
    Route::put('/applications/{brand}', [HubController::class, 'saveBrand'])->name('applications.update');
    Route::get('/posts', [HubController::class, 'posts'])->name('posts');
    Route::get('/posts/new', [HubController::class, 'postForm'])->name('posts.create');
    Route::post('/posts', [HubController::class, 'savePost'])->name('posts.store');
    Route::get('/posts/{post}/edit', [HubController::class, 'postForm'])->name('posts.edit');
    Route::put('/posts/{post}', [HubController::class, 'savePost'])->name('posts.update');
    Route::post('/posts/{post}/review', [HubController::class, 'reviewPost'])->name('posts.review');
    Route::get('/ai-providers', [HubController::class, 'providers'])->name('providers');
    Route::put('/ai-providers/{provider}', [HubController::class, 'saveProvider'])->name('providers.update');
});

Route::post('/api/v1/content', [ApplicationIntakeController::class, 'store'])->middleware('throttle:30,1');
Route::post('/api/v1/events', [ApplicationIntakeController::class, 'event'])->middleware('throttle:120,1');

Route::get('/webhooks/facebook', [FacebookWebhookController::class, 'verify']);
Route::post('/webhooks/facebook', [FacebookWebhookController::class, 'receive'])->middleware('throttle:120,1');
