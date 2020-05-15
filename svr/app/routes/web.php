<?php

use Illuminate\Support\Facades\Route;

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

Auth::routes();

Route::get('/home', 'HomeController@index')->name('home');
Route::get('/home1', 'HomeController@home1')->name('home1');

Route::get('/login/{social}', 'Auth\LoginController@socialLogin')
    ->where('social', 'facebook|twitter');
Route::get('/login/{social}/callback', 'Auth\LoginController@handleProviderCallback')
    ->where('social', 'facebook|twitter');
