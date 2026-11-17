<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Services\ErpNextClient;
use App\Services\PosProfileSync;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * First-run setup: 1) replace the bootstrap password  2) connect ERPNext
 * 3) choose which POS profiles this terminal serves. Each step is reached
 * through EnsureSetupComplete — there is no wizard state stored anywhere.
 */
class SetupController extends Controller
{
    public function __construct(protected PosProfileSync $profileSync) {}

    // ---- Step 1: password ------------------------------------------------------
    public function showPassword()
    {
        return view('pos.setup.password');
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        $request->user()->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
        ])->save();

        return redirect()->route('home')->with('success', 'Password updated.');
    }

    // ---- Step 2: connect ERPNext -----------------------------------------------
    public function showConnect()
    {
        return view('pos.setup.connect', ['setting' => Setting::current()]);
    }

    public function storeConnect(Request $request)
    {
        $setting = Setting::current();

        $data = $request->validate([
            'erpnext_url' => ['required', 'url', 'max:255'],
            'erpnext_api_key' => ['required', 'string', 'max:128'],
            'erpnext_api_secret' => [Rule::requiredIf(! $setting->erpnext_api_secret), 'nullable', 'string', 'max:255'],
        ]);
        // Blank secret = keep the stored one (the form never echoes it back).
        $secret = $data['erpnext_api_secret'] ?? null ?: (string) $setting->erpnext_api_secret;

        try {
            $erpUser = ErpNextClient::make($data['erpnext_url'], $data['erpnext_api_key'], $secret)->verify()['user'];
        } catch (Throwable $e) {
            return back()->withInput($request->except('erpnext_api_secret'))
                ->with('error', 'Could not connect: '.$e->getMessage());
        }

        $setting->fill([
            'erpnext_url' => $data['erpnext_url'],
            'erpnext_api_key' => $data['erpnext_api_key'],
            'erpnext_api_secret' => $secret,
            'erpnext_verified_at' => now(),
        ])->save();
        Setting::flushCache();

        return redirect()->route('setup.profiles')->with('success', "Connected to ERPNext as {$erpUser}.");
    }

    // ---- Step 3: choose POS profiles -----------------------------------------------
    public function showProfiles()
    {
        $remote = [];
        $error = null;
        try {
            $remote = app(ErpNextClient::class)->list(
                'POS Profile',
                ['name', 'company', 'currency', 'warehouse'],
                [['disabled', '=', 0]],
                200
            );
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('pos.setup.profiles', [
            'remote' => $remote,
            'error' => $error,
            'enabled' => PosProfile::whereNotNull('erpnext_id')->where('device_enabled', true)->pluck('erpnext_id')->all(),
        ]);
    }

    public function storeProfiles(Request $request)
    {
        $data = $request->validate([
            'profiles' => ['required', 'array', 'min:1'],
            'profiles.*' => ['string', 'max:140'],
        ], ['profiles.required' => 'Select at least one POS profile for this terminal.']);

        $synced = [];
        $errors = [];
        foreach ($data['profiles'] as $name) {
            try {
                $synced[] = $this->profileSync->syncOne($name)->erpnext_id;
            } catch (Throwable $e) {
                $errors[] = "{$name}: ".$e->getMessage();
            }
        }

        if (! $synced) {
            return back()->with('error', 'No profile could be synced. '.implode(' | ', array_slice($errors, 0, 3)));
        }

        // Only the ticked profiles are served by this terminal.
        PosProfile::whereNotNull('erpnext_id')->whereIn('erpnext_id', $synced)->update(['device_enabled' => true]);
        PosProfile::whereNotNull('erpnext_id')->whereNotIn('erpnext_id', $synced)->update(['device_enabled' => false]);

        $msg = 'Terminal ready: '.implode(', ', $synced).'. Cashiers imported from ERPNext need a PIN (Users page).';

        return redirect()->route('home')->with($errors ? 'warning' : 'success', $msg.($errors ? ' Skipped: '.implode(' | ', $errors) : ''));
    }

    // ---- Cashiers while setup is unfinished ---------------------------------------
    public function waiting()
    {
        return view('pos.setup.waiting');
    }
}
