<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        if (empty($data['pos_pin'])) {
            unset($data['pos_pin']); // blank = keep the current PIN
        }
        $user->update($data);

        return back()->with('success', "{$user->name} updated.");
    }

    /** @return list<string> */
    protected function assignableRoles(User $actor): array
    {
        return $actor->isAdmin()
            ? [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_CASHIER]
            : [User::ROLE_MANAGER, User::ROLE_CASHIER];
    }
}
