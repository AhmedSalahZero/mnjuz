<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\BillingResource;
use App\Http\Resources\BillingSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Models\BillingTransaction;
use App\Models\Chat;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserAdmin;
use App\Helpers\CurrencyHelper;
use Hash;
use Helper;
use Session;
use Validator;

class DashboardController extends BaseController
{
    public function index(Request $request){
        $billingRows = BillingTransaction::whereHas('organization', function ($query) {
                $query->whereNull('deleted_at');
            })
            ->with('organization')
            ->limit(5)
            ->orderBy('created_at', 'desc')
            ->get();
        $totalRevenue = BillingTransaction::whereHas('organization', function ($query) {
                $query->whereNull('deleted_at');
            })->where('entity_type', '=', 'payment')->sum('amount');
        $data['totalRevenue'] = CurrencyHelper::formatCurrency($totalRevenue);
        $data['userCount'] = User::where('role', '=', 'user')->where('deleted_at', NULL)->count();
        $data['openTickets'] = Ticket::whereHas('user', function ($query) {
                $query->whereNull('deleted_at');
            })->where('status', '=', 'open')->count();
        $data['totalMessages'] = Chat::count();
        $data['payments'] = BillingResource::collection($billingRows);
        $data['period'] = $this->period();
        $data['newUsers'] = $this->newUsers();
        $data['revenue'] = $this->revenue();
        $data['title'] = __('Dashboard');
        return Inertia::render('Admin/Dashboard', $data);
    }

    private function period(){
        $currentDate = Carbon::now();
        $dateArray = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDate->startOfDay();
            $dateArray[] = $currentDate->format('Y-m-d\TH:i:s.000\Z');
            $currentDate->subDay();
        }

        $dateArray = array_reverse($dateArray);

        return $dateArray;
    }

    private function newUsers(){
        $userCounts = [];

        foreach($this->period() as $dateString){
            $date = Carbon::parse($dateString);
            $userCount = User::whereDate('created_at', $date->toDateString())->count();
            $userCounts[] = $userCount;
        }

        return $userCounts;
    }

    private function revenue(){
        $billingCounts = [];

        foreach($this->period() as $dateString){
            $date = Carbon::parse($dateString);
            $billingCount = BillingTransaction::whereHas('organization', function ($query) {
                    $query->whereNull('deleted_at');
                })->where('entity_type', '=', 'payment')
                ->whereDate('updated_at', $date->toDateString())
                ->count();
                
            $billingCounts[] = $billingCount;
        }

        return $billingCounts;
    }
}
