<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\PageController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth'])->name('dashboard');

require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| Admin section.
|--------------------------------------------------------------------------
*/
Route::group(['prefix'=>'admin', 'middleware' => ['auth:sanctum', 'verified']], function () {

    Route::get('/', [PageController::class, 'index'])->name('admin_page');

    // News section.
    Route::group(['prefix'=>'pages'], function () {
        Route::get('/',            [PageController::class, 'index'])->name('admin_page_list');
        Route::get('create',       [PageController::class, 'create'])->name('admin_page_create');
        Route::post('store',       [PageController::class, 'store'])->name('admin_page_store');
        Route::get('edit/{id}',    [PageController::class, 'edit'])->name('admin_page_edit');
        Route::post('update/{id}', [PageController::class, 'update'])->name('admin_page_update');
        Route::post('delete',      [PageController::class, 'delete'])->name('admin_page_delete');
        Route::get('view/{id}',    [PageController::class, 'view'])->name('admin_page_view');
    });
});
