<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class EmailSettingsController extends Controller
{
    /** Defaults for every Email Delivery setting. Keys are Setting table keys. */
    public static function defaults(): array
    {
        return [
            'mail_transport' => 'log',
            'sendgrid_api_key' => '',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'mail_from' => '',
            'mail_from_name' => '',
        ];
    }

    public function edit()
    {
        $map = Setting::map();
        $v = [];
        foreach (static::defaults() as $key => $default) {
            $v[$key] = $map[$key] ?? $default;
        }

        return view('settings.email', [
            'v' => $v,
            'testTo' => auth()->user()->email ?? '',
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'mail_transport' => ['required', Rule::in(['sendgrid', 'smtp', 'mail', 'log'])],
            'sendgrid_api_key' => ['nullable', 'string', 'max:255'],
            'smtp_host' => ['nullable', 'string', 'max:191'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => ['nullable', 'string', 'max:191'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'mail_from' => ['nullable', 'email', 'max:191'],
            'mail_from_name' => ['nullable', 'string', 'max:191'],
        ]);

        Setting::put('mail_transport', $data['mail_transport']);
        Setting::put('smtp_encryption', $data['smtp_encryption']);
        foreach (['smtp_host', 'smtp_port', 'smtp_username', 'mail_from', 'mail_from_name'] as $k) {
            Setting::put($k, (string) ($data[$k] ?? ''));
        }
        // Keep stored secrets when the field is left blank.
        if (! empty($data['sendgrid_api_key'])) {
            Setting::put('sendgrid_api_key', $data['sendgrid_api_key']);
        }
        if (! empty($data['smtp_password'])) {
            Setting::put('smtp_password', $data['smtp_password']);
        }

        AuditLog::record('updated', 'Email delivery settings updated');

        return redirect()->route('settings.email.edit')->with('status', 'Email delivery settings saved.');
    }

    public function test(Request $request)
    {
        $data = $request->validate([
            'test_to' => ['required', 'email', 'max:191'],
        ]);
        $to = $data['test_to'];

        try {
            Mail::raw(
                'This is a test email from ' . config('brand.name') . '. Your email delivery configuration is working.',
                function ($m) use ($to) {
                    $m->to($to)->subject('[' . config('brand.name') . '] Test Email');
                }
            );

            $transport = Setting::get('mail_transport', 'log');
            $note = $transport === 'log'
                ? ' (log transport: written to the application log, not actually delivered)'
                : '';

            return back()->with('status', "Test email sent to {$to}{$note}.");
        } catch (\Throwable $e) {
            return back()->with('warning', 'Could not send test email: ' . $e->getMessage());
        }
    }
}
