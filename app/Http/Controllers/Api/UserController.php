<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(
            User::select('id', 'naam', 'email', 'rol', 'kleur', 'actief', 'created_at')
                ->orderBy('naam')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'naam' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'wachtwoord' => 'required|string|min:3',
        ]);

        try {
            $user = User::create([
                'naam' => $request->naam,
                'email' => $request->email,
                'wachtwoord_hash' => Hash::make($request->wachtwoord),
                'rol' => $request->rol ?? 'lid',
                'kleur' => $request->kleur ?? '#3B82F6',
            ]);

            return response()->json([
                'id' => $user->id,
                'naam' => $user->naam,
                'email' => $user->email,
                'rol' => $user->rol,
                'kleur' => $user->kleur,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Email bestaat al'], 400);
        }
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        $data = [
            'naam' => $request->naam,
            'email' => $request->email,
            'rol' => $request->rol,
            'kleur' => $request->kleur,
            'actief' => $request->actief ? true : false,
        ];

        if ($request->filled('wachtwoord')) {
            $data['wachtwoord_hash'] = Hash::make($request->wachtwoord);
        }

        $user->update($data);
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $id)
    {
        if ($id === $request->session()->get('userId')) {
            return response()->json(['error' => 'Je kunt jezelf niet verwijderen'], 400);
        }

        User::where('id', $id)->update(['actief' => false]);
        return response()->json(['ok' => true]);
    }
}
