# Docker+LaravelでSNSログイン

## 目的
開発プロジェクトでSNSログインを行う場合、

* SSLが必要になる事
* ローカル専用ドメインでSSLがしたい

本番用のドメインでSSLを作成して、hosts等で本番ドメインをローカルホストにアクセスするように設定すれば良いが、それはそれで後々問題が起きそうなので、今回は

* ローカル専用ドメインを用意
* ローカル専用SSLを作成
* Docker上で使用する

この条件で、開発環境の構築からLaravelでSNSログインの実装までを説明する。

## 前提
下記の環境で実装した。

* ローカルはmacos
* Dockerコンテナはnginx+phpfpm
* DockerコンテナのnginxにローカルSSLを組み込む
* ローカル専用ドメインはsnslogin.dev.cdeとする
* コンテナはdocker-composeを使用する
* UIはとりあえずbootstrapで行う


## ローカルホスト側の設定
ローカル専用SSLはmkcertを使用する。  
よってmacosのターミナルで

```
% brew install mkcert                 # mkcertをhomebrewからインストール
% mkcert --install                    # ローカルSSL初期設定
% mkcert localhost dev.cde *.dev.cde  # ローカルドメイン用のSSLを作成
% mkcert -cert-file ./snslogin_dev_cde.crt.pem \
    -key-file ./snslogin_dev_cde.key.pem snslogin.dev.cde # key,certファイルの作成
```
以上で、SSLの必要なファイルを作成した。
作成したファイルはSslディレクトリにコピーしておく。

## nginxコンテナ
nginxコンテナの作成は下記の通りである。

```
services:

  nginx:
    image: nginx
    container_name: "snslogin-nginx"
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./svr:/svr
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf
      - ./Ssl/snslogin_dev_cde.crt.pem:/etc/nginx/conf.d/snslogin_dev_cde.cert.pem
      - ./Ssl/snslogin_dev_cde.key.pem:/etc/nginx/conf.d/snslogin_dev_cde.key.pem
      - ./weblog:/var/log/nginx
    depends_on:
      - phpfpm

  phpfpm:
    build: ./phpfpm
```

次にnginxコンテナ上のdefault.confにてSSLの設定を行う。

```
server {
    index index.php index.html;
    root /svr/app/public;

    listen       443 ssl;
    server_name  snslogin.dev.cde;

    ssl_certificate      /etc/nginx/conf.d/snslogin_dev_cde.cert.pem;
    ssl_certificate_key  /etc/nginx/conf.d/snslogin_dev_cde.key.pem;

    ssl_session_cache    shared:SSL:1m;
    ssl_session_timeout  5m;

    ssl_ciphers  HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers  on;
    
    〜以下省略〜
```

## Laravelの設定
ここで、一旦コンテナを起動して、Laravelの設定を行う。
phpfpmに接続して、コマンドラインから

```
% laravel new app
% composer require laravel/socialite 
% composer require laravel/ui
% php artisan ui bootstrap
% php artisan ui bootstrap --auth
% npm install
% npm run dev
```
以上を行う。  
Laravelのパッケージはlaravel/socialite、laravel/uiをインストールする。  
UIはbootstrapを使用し、認証スカフォールドを生成する。  

## テーブル設定
laravel標準のusersを使用するが、SNSログインの場合、パスワードがNULLになるため、マイグレーションを変更しておく。

```
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable(); // ここ。
            $table->rememberToken();
            $table->timestamps();
        });
    }
    
    〜以下省略〜
```
passwordをnullableしておく。
これが済めば、.envにDB接続情報を設定してから、マイグレーションを実行する。

```
% php artisan migrate
```

## ルーティングとサービスの設定
まず、route/web.phpにルーティングの設定を行う。

```
Route::get('/login/{social}', 'Auth\LoginController@socialLogin')
    ->where('social', 'facebook|twitter');
Route::get('/login/{social}/callback', 'Auth\LoginController@handleProviderCallback')
    ->where('social', 'facebook|twitter');
```

SNSログインのURLとコールバックのURLを設定した。  
次にサービスの設定をconfig/service.phpに記述する。  

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],
〜中略〜

　// 以下を追加
    'facebook' => [
        'client_id' => env('FACEBOOK_API_ID'),
        'client_secret' => env('FACEBOOK_API_SECRET'),
        'redirect' => env('FACEBOOK_CALLBACKURL'),
    ],
];
```
とりあえず、facebookの設定のみを行った。  
実際の値は、.envにて設定する。

## コントローラとViewの編集
コントローラーは認証スカフォールドで作成されたLoginControllerを使用する。

```
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\AuthenticatesUsers;

// ここから
use Socialite; 
use App\User;
use Auth;
// ここまで追加

class LoginController extends Controller
{

〜中略〜
    // SNSログインのメソッド
    public function socialLogin($social)
    {
        return Socialite::driver($social)->redirect();
    }

    // SNSログインコールバックのメソッド
    public function handleProviderCallback($social)
    {
        $userSocial = Socialite::driver($social)->stateless()->user();
        $user = User::where(['email' => $userSocial->getEmail()])->first();

        if ($user) {
            Auth::login($user);
        } else {
            $newuser = new User;
            $newuser->name = $userSocial->getName();
            $newuser->email = $userSocial->getEmail();
            $newuser->save();

            Auth::login($newuser);
        }
        $uri = "/home";
        if (session()->has("login_after_url")) {   // ログイン後リダイレクト
            $uri = session("login_after_url");     
            session()->forget("login_after_url");
        }
        return redirect($uri);
    }
}
```
ここでは、SNSログインの開始とコールバックの処理を行う。  
コールバック時にログインユーザがusersになければ、作成して、  
Auth::login()にて強制ログインする。  
SNSログイン開始前にセッション変数login\_after\_urlにログイン後のリダイレクト先URLを  
設定しておくと、そのURLに遷移する。   
    
Viewは同じく認証スカフォールドで作成されたresources/views/auth/login.blade.php  
にSNSログインのリンクを記述する。

```
<div class="form-group row">
   <label for="name" class="col-sm-4 col-form-label text-md-right">
     Login With
   </label>
   <div class="col-md-6">
      <a href="{{ url('login/facebook')}}" 
        class="btn btn-social-icon btn-facebook">
        <i class="fa fa-facebook"></i>Facebookログイン
      </a>
   </div>
</div>
```

以上で、ローカル開発環境にSNSログインの組み込みは完了である。  
実際に実行する前にSNS側のアプリ設定とログインコールバックのURLを設定すればOKである。

## 考察
意外と簡単にできたのである。  
laravel/socialiteで他に何ができるかは未調査であるが、少なくともSNSログインは簡単にできたのである。  
むしろdocker-compse.ymlやローカルSSLの方が大変だった。  
作成したプロジェクトは[GitHub](https://github.com/kingcony/snslogin)にアップしたので、必要であれば参照していただきたい。

