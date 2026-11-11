<?php

use App\Livewire;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('ops.dashboard'));
Route::get('/dashboard', Livewire\Ops\Dashboard::class)->name('dashboard');
