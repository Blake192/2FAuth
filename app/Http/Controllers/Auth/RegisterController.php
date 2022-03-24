<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Http\Requests\UserStoreRequest;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
// use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\RegistersUsers;
use App\Exceptions\UnsupportedWithReverseProxyException;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;


    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $authGuard = config('auth.defaults.guard');

        if ($authGuard === 'reverse-proxy-guard') {
            throw new UnsupportedWithReverseProxyException();
        }
    }


    /**
     * Handle a registration request for the application.
     *
     * @param  \App\Api\v1\Requests\UserStoreRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(UserStoreRequest $request)
    {
        $validated = $request->validated();
        event(new Registered($user = $this->create($validated)));

        $this->guard()->login($user);
        // $this->guard()->loginUsingId($user->id);
        // Auth::guard('admin')->attempt($credentials);

        return response()->json([
            'message' => 'account created',
            'name' => $user->name,
        ], 201);
    }


    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return \App\Models\User
     */
    protected function create(array $data)
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }
}
