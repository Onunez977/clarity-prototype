<?php

namespace App\Http\Controllers;

use App\Models\AlertSetting;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    public function unsubscribeFromAlert(Request $request)
    {
        if (! $request->hasValidSignature()) {
            return response('The link has expired or is invalid.', 403);
        }

        $alertSetting = AlertSetting::findOrFail($request->get('alert_setting_id'));

        $alertSetting->removeEmail($request->get('email'));

        activity('Alert Settings Unsubscribe')
            ->performedOn($alertSetting)
            ->withProperties([
                'email' => $request->get('email'),
            ])
            ->log('Unsubscribed from alert');

        return response(content: 'You have been unsubscribed from this alert.', status: 200);
    }

    public function unsubscribeFromAllAlerts(Request $request)
    {
        if (! $request->hasValidSignature()) {
            return response('The link has expired or is invalid.', 403);
        }

        $alertSettings = AlertSetting::alertsEmailIsSubscribedTo($request->get('email'));

        $email = $request->get('email');

        $alertSettings->each(function (AlertSetting $alertSetting) use ($email): void {
            $alertSetting->removeEmail($email);

            activity('Alert Settings Unsubscribe')
                ->performedOn($alertSetting)
                ->withProperties(['email' => $email])
                ->log('Unsubscribed from all alerts');
        });

        return response('You have been unsubscribed from '.$alertSettings->count().' alerts.');
    }
}
