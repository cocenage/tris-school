<?php

namespace App\Http\Controllers;

use App\Services\MobilePush\FcmTransport;
use App\Services\MobilePush\PushService;
use App\Services\RolePreviewContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobilePushController extends Controller
{
    private function guard(Request $request, bool $admin = false): void
    {
        abort_unless(PushService::enabled(), 404);
        abort_unless($request->user()?->isApproved(), 403);
        app(RolePreviewContext::class)->assertWritable();
        abort_if($admin && $request->user()->role !== 'admin', 403);
    }

    public function index(Request $request)
    {
        $this->guard($request);
        $history = DB::table('mobile_push_notifications')->where('user_id', $request->user()->id)
            ->latest()->limit(50)->get();
        return response()->view('mobile.push', ['history' => $history])->header('Cache-Control', 'private, no-store');
    }

    public function register(Request $request, PushService $push)
    {
        $this->guard($request);
        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:4096', 'regex:/^[A-Za-z0-9_:\\-.]+$/D']]);
        $push->register($request, $data['token']);
        return response()->json(['ok' => true]);
    }

    public function revoke(Request $request, PushService $push)
    {
        $this->guard($request);
        $push->revoke($request);
        return response()->json(['ok' => true]);
    }

    public function event(Request $request)
    {
        $this->guard($request);
        $data = $request->validate(['id' => ['required', 'uuid'], 'event' => ['required', 'in:received,opened']]);
        $devices = DB::table('mobile_push_devices')->where('user_id', $request->user()->id)
            ->where('session_hash', PushService::sessionHash($request))->whereNull('revoked_at')->select('id');
        $column = $data['event'].'_at';
        DB::table('mobile_push_notifications')->where('id', $data['id'])
            ->where('user_id', $request->user()->id)->whereIn('device_id', $devices)
            ->whereNull($column)->update([$column => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function admin(Request $request)
    {
        $this->guard($request, true);
        $devices = DB::table('mobile_push_devices as d')->join('users as u', 'u.id', '=', 'd.user_id')
            ->whereNull('d.revoked_at')->where('d.last_seen_at', '>=', now()->subDay())
            ->where('u.status', 'approved')->where('u.is_active', true)
            ->select('d.id', 'd.last_seen_at', 'u.name', 'u.tris_id')->latest('d.last_seen_at')->limit(100)->get();
        $history = DB::table('mobile_push_notifications')->latest()->limit(50)->get();
        return response()->view('mobile.push-admin', compact('devices', 'history'))->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, PushService $push, FcmTransport $transport)
    {
        $this->guard($request, true);
        $data = $request->validate(['device_id' => ['required', 'integer', 'min:1']]);
        $status = $push->sendTest((int) $data['device_id'], $transport);
        return back()->with('push_result', $status);
    }
}
