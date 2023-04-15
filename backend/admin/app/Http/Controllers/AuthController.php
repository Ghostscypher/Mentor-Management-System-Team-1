<?php

namespace App\Http\Controllers;

use App\Helpers\AppConstants;
use App\Http\Resources\ApiResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /**
     * Create a new AuthController instance.
     *
     * @param User $user
     */
    protected function createAuthToken($user, array $scopes = ['*']): array
    {
        // Update the last login time and IP
        $user->last_login_at = now();
        $user->last_login_ip = request()->ip();
        $user->save();

        // Get expiry time from config
        $expiryTime = config('sanctum.expiration');
        $expiryTime = $expiryTime ? now()->addMinutes($expiryTime) : null;
        $token = $user->createToken('authToken', $scopes, $expiryTime);

        return [
            'access_token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at->timestamp,
            'expires_in' => $token->accessToken->expires_at->diffInSeconds(now()),
        ];
    }

    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function register(Request $request)
    {
        // Validate the user, return error using the ApiResource
        $validator = validator($request->all(), [
            'name' => 'required|string|between:2,100',
            'email' => 'required|string|email|max:100|unique:users',
            'password' => 'required|string|confirmed|min:6',
        ]);

        if ($validator->fails()) {
            return new ApiResource(['errors' => $validator->errors(), 'status' => 422]);
        }

        $validatedData = $validator->validated();
        $validatedData['password'] = bcrypt($request->password);
        $validatedData['role'] = AppConstants::ROLE_ADMIN;

        $user = new User($validatedData);
        $user->save();
        $accessToken = $this->createAuthToken($user);

        return new ApiResource([
            'user' => $user,
            'status' => 201,
            'message' => 'User successfully registered',
        ] + $accessToken);
    }

    /**
     * Login a user.
     *
     * @return void
     */
    public function login(Request $request)
    {
        // Validate the user, return error using the ApiResource
        $validator = validator($request->all(), [
            'email' => 'required|string|email|max:100',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return new ApiResource(['errors' => $validator->errors(), 'status' => 422]);
        }

        $credentials = request(['email', 'password']);

        if (! auth()->attempt($credentials)) {
            return new ApiResource(['error' => 'Invalid username or password.', 'status' => 401]);
        }

        $user = auth()->user();
        $accessToken = $this->createAuthToken($user);

        return new ApiResource([
            'user' => $user,
            'message' => 'User successfully logged in',
        ] + $accessToken);
    }

    /**
     * Logout a user.
     *
     * @return void
     */
    public function logout(Request $request)
    {
        // Check if the user is logged in
        $request->user()->currentAccessToken()->delete();

        return new ApiResource(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh a token.
     *
     * @return void
     */
    public function refresh()
    {
        $accessToken = $this->createAuthToken(auth()->user());

        return new ApiResource([
            'message' => 'Token successfully refreshed',
        ] + $accessToken);
    }

    /**
     * Get the authenticated User.
     *
     * @return void
     */
    public function user(Request $request)
    {
        return new ApiResource(['user' => $request->user()]);
    }

    /**
     * Login with social media.
     *
     * @return void
     */
    public function socialLogin(Request $request)
    {
        // Validate the user, return error using the ApiResource
        $validator = validator($request->all(), [
            'provider' => 'required|string',
            'access_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return new ApiResource(['errors' => $validator->errors(), 'status' => 422]);
        }

        $provider = $request->provider;
        $accessToken = $request->access_token;

        $socialite = new Socialite();
        $socialUser = $socialite->__callStatic('driver', [$provider])->userFromToken($accessToken);
        $user = User::where('email', $socialUser->getEmail())->first();

        if (! $user) {
            $user = new User([
                'name' => $socialUser->getName(),
                'email' => $socialUser->getEmail(),
                'password' => callStatic(Hash::class, 'make', strHelper('random', 24)),
                'role' => AppConstants::ROLE_ADMIN,
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'avatar' => $socialUser->getAvatar(),
                'email_verified_at' => now(),
            ]);

            $user->save();
        }

        $accessToken = $this->createAuthToken($user);

        return new ApiResource([
            'user' => $user,
        ] + $accessToken);
    }

    /**
     * Social login callback.
     *
     * @param mixed $provider
     */
    public function socialLoginCallback(Request $request, $provider)
    {
        // Validate list of providers
        $providers = AppConstants::SOCIAL_PROVIDERS;

        if (! in_array($provider, $providers)) {
            return new ApiResource(['error' => 'Invalid provider']);
        }

        // Get access token, given state and code from google callback
        $socialite = new Socialite();

        try {
            $accessToken = $socialite->__callStatic('driver', [$provider])->getAccessTokenResponse($request->code);
            $socialUser = $socialite->__callStatic('driver', [$provider])->userFromToken($accessToken['access_token']);
        } catch (\Exception|\Throwable $e) {
            callStatic(Log::class, 'error', $e);

            return redirect()->to(env('FRONTEND_URL').'/login?error=Invalid+credentials');
        }

        $user = new User();
        $user = $user->__callStatic('where', ['email', $socialUser->getEmail()])->first();

        // Here the logic should be to redirect to the frontend with the access token
        if (! $user) {
            $user = new User([
                'name' => $socialUser->getName(),
                'email' => $socialUser->getEmail(),
                'password' => callStatic(Hash::class, 'make', strHelper('random', 24)),
                'role' => AppConstants::ROLE_ADMIN,
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'avatar' => $socialUser->getAvatar(),
                'email_verified_at' => now(),
            ]);

            $user->save();
        }

        $accessToken = $this->createAuthToken($user);
        $query = http_build_query($accessToken);
        $url = config('services.frontend.url').'/login?'.$query;

        return redirect()->away($url);
    }

    /**
     * Social login redirect.
     */
    public function socialLoginRedirect(Request $request)
    {
        $provider = $request->provider;

        // Validate list of providers
        $providers = AppConstants::SOCIAL_PROVIDERS;

        if (! in_array($provider, $providers)) {
            return new ApiResource(['error' => 'Invalid provider, valid providers are: '.implode(',', $providers), 'status' => 422]);
        }

        return callStatic(Socialite::class, 'driver', $provider)->redirect();
    }
}
