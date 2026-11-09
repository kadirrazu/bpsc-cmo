<?php

namespace App\Http\Controllers;

use App\Models\Evaluation;
use App\Models\Expert;
use App\Models\Survey;

class EvaluationModuleController extends Controller
{
    public function index()
    {
        $u = auth()->user();
        $stats = [];
        if ($u->can('view-experts')) {
            $stats['Active Experts'] = Expert::where('is_active', true)->count();
        }
        if ($u->can('manage-surveys')) {
            $stats['Surveys'] = Survey::count();
        }
        if ($u->can('view-evaluation-data')) {
            $stats['Responses'] = Evaluation::count();
        }
        if ($u->can('evaluate-experts')) {
            $stats['Available Surveys'] = Survey::availableTo($u)->count();
            $stats['My Responses'] = Evaluation::where('user_id', $u->id)->count();
        }

        return view('modules.evaluation.home', compact('stats'));
    }
}
