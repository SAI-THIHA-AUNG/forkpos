<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Manager-facing user list: set PINs for users imported from ERPNext, add local users. */
class UserController extends Controller
{
    public function index()
    {
        return view('pos.users.index', ['users' => User::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
            'pos_pin' => ['nullable', 'string', 'digits_between:4,8'],
        ]);
        $this->assertPinFree($data['pos_pin'] ?? null, null);

        User::create($data + ['is_active' => true]);

        return back()->with('success', 'User created.');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in($this->assignableRoles($request->user()))],
            'is_active' => ['sometimes', 'boolean'],
            'pos_pin' => ['nullable', 'string', 'digits_between:4,8'],
        ]);
        if ($user->isAdmin() && ! $request->user()->isAdmin()) {
            abort(403, 'Only an administrator can change an administrator.');
        }
        $this->assertPinFree($data['pos_pin'] ?? null, $user->id);

        if (empty($data['pos_pin'])) {
            unset($data['pos_pin']); // blank = keep the current PIN
        }
        $user->update($data);

        return back()->with('success', "{$user->name} updated.");
    }

    /** A PIN identifies a person at login, so two users must never share one. */
    protected function assertPinFree(?string $pin, ?int $exceptUserId): void
    {
        if (! $pin) {
            return;
        }
        $taken = User::whereNotNull('pos_pin')
            ->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))
            ->get()
            ->contains(fn (User $u) => Hash::check($pin, $u->pos_pin));
        if ($taken) {
            throw ValidationException::withMessages(['pos_pin' => ['That PIN is already used by another user. Pick a different one.']]);
        }
    }

    /** @return list<string> */
    protected function assignableRoles(User $actor): array
    {
        return $actor->isAdmin()
            ? [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_CASHIER]
            : [User::ROLE_MANAGER, User::ROLE_CASHIER];
    }
}
