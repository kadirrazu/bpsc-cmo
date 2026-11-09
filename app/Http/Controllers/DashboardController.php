<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Survey;

class DashboardController extends Controller
{
    public function index()
    {
        $memberSurveys = auth()->user()->role === UserRole::Member ? Survey::availableTo(auth()->user())->latest('id')->paginate(6, ['*'], 'active_surveys_page') : null;

        return view('dashboard', compact('memberSurveys'));
    }
}
