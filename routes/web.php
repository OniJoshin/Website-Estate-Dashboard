<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('admin/users', 'pages::admin.users.index')
        ->middleware('can:admin')
        ->name('admin.users.index');
});

require __DIR__.'/settings.php';
