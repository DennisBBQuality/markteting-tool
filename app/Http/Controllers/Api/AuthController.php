<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'wachtwoord' => 'required',
        ]);

        $user = User::where('email', $request->email)->where('actief', true)->first();

        if (!$user || !password_verify($request->wachtwoord, $user->wachtwoord_hash)) {
            return response()->json(['error' => 'Ongeldige inloggegevens'], 401);
        }

        $request->session()->put('userId', $user->id);
        $request->session()->put('rol', $user->rol);

        return response()->json([
            'id' => $user->id,
            'naam' => $user->naam,
            'email' => $user->email,
            'rol' => $user->rol,
            'kleur' => $user->kleur,
        ]);
    }

    public function logout(Request $request)
    {
        $request->session()->flush();
        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        $user = User::select('id', 'naam', 'email', 'rol', 'kleur', 'avatar')
            ->find($request->session()->get('userId'));

        if (!$user) {
            return response()->json(['error' => 'Gebruiker niet gevonden'], 401);
        }

        return response()->json($user);
    }
}
