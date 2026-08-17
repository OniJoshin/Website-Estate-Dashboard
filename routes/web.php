<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('servers', 'pages::servers.index')->name('servers.index');

    Route::livewire('admin/users', 'pages::admin.users.index')
        ->middleware('can:admin')
        ->name('admin.users.index');

    Route::livewire('admin/servers/create', 'pages::admin.servers.create')
        ->middleware('can:admin')
        ->name('admin.servers.create');

    Route::livewire('admin/servers/{server}/edit', 'pages::admin.servers.edit')
        ->middleware('can:admin')
        ->name('admin.servers.edit');
});

require __DIR__.'/settings.php';
