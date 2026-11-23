<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        return User::select(['id', 'name', 'email', 'role', 'is_active', 'created_at'])
            ->withCount(['sales', 'stockMovements'])
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        // Emails are always lowercase and without spaces
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(['admin', 'attendant'])],
        ]);

        $user = User::create($data + ['is_active' => true]);

        return response()->json($user->only(['id', 'name', 'email', 'role', 'is_active', 'created_at']), 201);
    }

    public function update(Request $request, User $user)
    {
        // Emails are always lowercase and without spaces
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'role' => ['required', Rule::in(['admin', 'attendant'])],
            'is_active' => 'required|boolean',
        ]);

        // The owner cannot lock themselves out
        if ($user->id === $request->user()->id && ($data['role'] !== 'admin' || ! $data['is_active'])) {
            throw ValidationException::withMessages([
                'role' => ['You cannot deactivate or demote your own account.'],
            ]);
        }

        $passwordChanged = ! empty($data['password']);

        if (! $passwordChanged) {
            unset($data['password']);
        }

        $user->update($data);

        // Deactivated or reset accounts must sign in again,
        // but the owner keeps their own current session when editing their own account
        if (! $user->is_active || $passwordChanged) {
            $tokens = $user->tokens();

            if ($user->id === $request->user()->id) {
                $tokens->where('id', '!=', $request->user()->currentAccessToken()->id);
            }

            $tokens->delete();
        }

        return $user->only(['id', 'name', 'email', 'role', 'is_active', 'created_at']);
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        // Anyone who has recorded a sale or a stock change must stay on record
        if ($user->sales()->exists() || $user->stockMovements()->exists()) {
            return response()->json([
                'message' => 'This user has sales or stock records and cannot be deleted. Deactivate the account instead.',
            ], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }
}