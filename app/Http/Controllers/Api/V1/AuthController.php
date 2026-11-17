<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     * Body: {email, password}  — or —  {pin}
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required_without:pin', 'email'],
            'password' => ['required_with:email', 'string'],
            'pin' => ['required_without:email', 'string', 'digits_between:4,8'],
        ]);

        $user = null;
        if (isset($credentials['pin'])) {
            // PIN login — POS terminals / Android app quick sign-in.
            $matches = User::where('is_active', true)->whereNotNull('pos_pin')->get()
                ->filter(fn (User $u) => Hash::check($credentials['pin'], $u->pos_pin));
            // Exactly one user must match: an ambiguous PIN must never pick "the first" account.
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['pin' => ['Invalid PIN.']]);
            }
            $user = $matches->first();
        } else {
            $user = User::where('email', $credentials['email'])->first();
            if (! $user || ! Hash::check($credentials['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => ['Invalid email or password.']]);
            }
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => ['This account is disabled.']]);
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
