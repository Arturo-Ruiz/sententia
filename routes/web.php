<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\SearchChat;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::get('/chat', SearchChat::class)->name('search.chat');
});

require __DIR__ . '/settings.php';
