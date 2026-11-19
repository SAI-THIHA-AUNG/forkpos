<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\UserLogin;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     * Body: {email, password}  — or —  {user_id, pin}  — or —  {email, pin}
     * A PIN always belongs to one chosen user; GET /api/v1/auth/users lists who can use one.
     */
    public function login(Request $request, UserLogin $userLogin)
    {
        $credentials = $request->validate([
            'email' => ['nullable', 'email'],
            'password' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'digits_between:4,8'],
        ]);

        if (filled($credentials['pin'] ?? null)) {
            $user = filled($credentials['user_id'] ?? null)
                ? User::find($credentials['user_id'])
                : User::where('email', $credentials['email'] ?? '')->first();
            if (! $user) {
                throw ValidationException::withMessages(['pin' => ['Send user_id (or email) together with the PIN.']]);
            }
            $user = $userLogin->withPin($user, $credentials['pin']);
        } elseif (filled($credentials['email'] ?? null) && filled($credentials['password'] ?? null)) {
            $user = $userLogin->withPassword($credentials['email'], $credentials['password']);
        } else {
            throw ValidationException::withMessages(['email' => ['Send email and password, or user_id and pin.']]);
        }

        if ($user->must_change_password) {
            throw ValidationException::withMessages(['email' => ['Password change required. Sign in on the web console first to set a new password.']]);
        }

        $token = $user->createToken('pos-'.($request->userAgent() ?: 'client'))->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/auth/users — names only, for the PIN picker (no email, no role).
     */
    public function pinUsers()
    {
        $users = config('forkpos.login_user_picker')
            ? User::where('is_active', true)->whereNotNull('pos_pin')->orderBy('name')->get(['id', 'name'])
            : collect();

        return response()->json(['data' => $users]);
    }

    /**
     * POST /api/v1/auth/logout — revokes the current token.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['message' => 'Logged out.']]);
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('posProfiles');

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'pos_profiles' => $user->posProfiles->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'is_default' => (bool) $p->pivot->is_default,
                ]),
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/register
     * Open only while no user exists (first-run bootstrap) or for admins.
     */
    public function register(Request $request)
    {
        $userCount = User::count();
        $bootstrap = $userCount === 0;

        if (! $bootstrap) {
            $request->user(); // 401 without a token
            if (! $request->user()->isAdmin()) {
                throw ValidationException::withMessages(['email' => ['Only administrators can create users.']]);
            }
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_CASHIER])],
            'pos_pin' => ['nullable', 'string', 'digits_between:4,8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $bootstrap && $data['role'] !== User::ROLE_ADMIN ? User::ROLE_ADMIN : $data['role'],
            'pos_pin' => $data['pos_pin'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 201);
    }
}
