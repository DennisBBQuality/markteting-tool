<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Notification;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $myId = $request->session()->get('userId');

        $notifications = DB::select("
            SELECT n.*, m.inhoud as bericht_inhoud,
                u.naam as afzender_naam, u.kleur as afzender_kleur,
                c.naam as channel_naam,
                CASE WHEN t.user1_id = ? THEN u2.naam ELSE u1.naam END as thread_andere_naam
            FROM notifications n
            LEFT JOIN chat_messages m ON n.message_id = m.id
            LEFT JOIN users u ON m.afzender_id = u.id
            LEFT JOIN chat_channels c ON n.channel_id = c.id
            LEFT JOIN chat_threads t ON n.thread_id = t.id
            LEFT JOIN users u1 ON t.user1_id = u1.id
            LEFT JOIN users u2 ON t.user2_id = u2.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT 50
        ", [$myId, $myId]);

        return response()->json($notifications);
    }

    public function unreadCount(Request $request)
    {
        $count = Notification::where('user_id', $request->session()->get('userId'))
            ->where('gelezen', false)->count();
        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, string $id)
    {
        Notification::where('id', $id)
            ->where('user_id', $request->session()->get('userId'))
            ->update(['gelezen' => true]);
        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request)
    {
        Notification::where('user_id', $request->session()->get('userId'))
            ->update(['gelezen' => true]);
        return response()->json(['ok' => true]);
    }

    public function markChannelRead(Request $request, string $channelId)
    {
        Notification::where('user_id', $request->session()->get('userId'))
            ->where('channel_id', $channelId)
            ->update(['gelezen' => true]);
        return response()->json(['ok' => true]);
    }

    public function markThreadRead(Request $request, string $threadId)
    {
        Notification::where('user_id', $request->session()->get('userId'))
            ->where('thread_id', $threadId)
            ->update(['gelezen' => true]);
        return response()->json(['ok' => true]);
    }
}
