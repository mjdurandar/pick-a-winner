<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\User;

class UsersController extends Controller
{
    public function index()
    {   
        return Inertia::render('Users', [
            'users' => User::all()
        ]);
    }

    public function update(Request $request, $userId)
    {   
        $user = User::find($userId);
        $user->role = $request->role;
        $user->save();
        return redirect()->route('users.index');
    }

    public function destroy($userId)
    {
        $user = User::find($userId);
        $user->delete();
        return redirect()->route('users.index');
    }
}
