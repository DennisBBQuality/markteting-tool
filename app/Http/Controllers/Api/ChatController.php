<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    // ========== CHANNELS ==========

    public function channels()
    {
        $channels = DB::select("
            SELECT c.*, p.naam as project_naam, p.kleur as project_kleur,
                (SELECT COUNT(*) FROM chat_messages WHERE channel_id = c.id) as bericht_count,
                (SELECT MAX(created_at) FROM chat_messages WHERE channel_id = c.id) as laatste_bericht
            FROM chat_channels c
            LEFT JOIN projects p ON c.project_id = p.id
            ORDER BY c.type ASC, c.naam ASC
        ");
        return response()->json($channels);
    }

    public function channel(string $id)
    {
        $channel = ChatChannel::select('chat_channels.*', 'p.naam as project_naam', 'p.kleur as project_kleur')
            ->leftJoin('projects as p', 'chat_channels.project_id', '=', 'p.id')
            ->where('chat_channels.id', $id)->first();

        if (!$channel) return response()->json(['error' => 'Kanaal niet gevonden'], 404);
        return response()->json($channel);
    }

    public function createChannel(Request $request)
    {
        $naam = trim($request->naam ?? '');
        if (!$naam) return response()->json(['error' => 'Naam is verplicht'], 400);

        $channel = ChatChannel::create([
            'naam' => $naam,
            'type' => 'general',
            'beschrijving' => $request->beschrijving ?? '',
            'aangemaakt_door' => $request->session()->get('userId'),
        ]);

        return response()->json($channel);
    }

    public function deleteChannel(Request $request, string $id)
    {
        $channel = ChatChannel::find($id);
        if (!$channel) return response()->json(['error' => 'Kanaal niet gevonden'], 404);
        if ($channel->type === 'project') return response()->json(['error' => 'Project kanalen kunnen niet verwijderd worden'], 400);
        $channel->delete();
        return response()->json(['ok' => true]);
    }

    // ========== CHANNEL MESSAGES ==========

    public function channelMessages(Request $request, string $id)
    {
        $limit = (int) ($request->query('limit', 50));
        $before = $request->query('before');

        $query = ChatMessage::select('chat_messages.*', 'u.naam as afzender_naam', 'u.kleur as afzender_kleur')
            ->join('users as u', 'chat_messages.afzender_id', '=', 'u.id')
            ->where('chat_messages.channel_id', $id);

        if ($before) $query->where('chat_messages.created_at', '<', $before);

        $messages = $query->orderByDesc('chat_messages.created_at')->limit($limit)->get();
        return response()->json($messages->reverse()->values());
    }

    public function sendChannelMessage(Request $request, string $id)
    {
        $inhoud = trim($request->inhoud ?? '');
        if (!$inhoud) return response()->json(['error' => 'Bericht is leeg'], 400);

        $channel = ChatChannel::find($id);
        if (!$channel) return response()->json(['error' => 'Kanaal niet gevonden'], 404);

        $userId = $request->session()->get('userId');
        $message = ChatMessage::create([
            'channel_id' => $id,
            'afzender_id' => $userId,
            'inhoud' => $inhoud,
        ]);

        // Parse @mentions
        preg_match_all('/@(\w+)/', $inhoud, $matches);
        $mentions = array_unique($matches[1] ?? []);
        foreach ($mentions as $username) {
            $user = User::where('naam', $username)->where('actief', true)->first();
            if ($user && $user->id !== $userId) {
                Notification::create([
                    'user_id' => $user->id,
                    'type' => 'mention',
                    'message_id' => $message->id,
                    'channel_id' => $id,
                ]);
            }
        }

        return response()->json(
            ChatMessage::select('chat_messages.*', 'u.naam as afzender_naam', 'u.kleur as afzender_kleur')
                ->join('users as u', 'chat_messages.afzender_id', '=', 'u.id')
                ->where('chat_messages.id', $message->id)->first()
        );
    }

    public function deleteMessage(Request $request, string $id)
    {
        $message = ChatMessage::find($id);
        if (!$message) return response()->json(['error' => 'Bericht niet gevonden'], 404);
        if ($message->afzender_id !== $request->session()->get('userId') && $request->session()->get('rol') !== 'admin') {
            return response()->json(['error' => 'Niet toegestaan'], 403);
        }
        $message->delete();
        return response()->json(['ok' => true]);
    }

    // ========== DIRECT MESSAGES ==========

    public function directThreads(Request $request)
    {
        $myId = $request->session()->get('userId');

        $threads = DB::select("
            SELECT t.*,
                CASE WHEN t.user1_id = ? THEN u2.naam ELSE u1.naam END as andere_naam,
                CASE WHEN t.user1_id = ? THEN u2.kleur ELSE u1.kleur END as andere_kleur,
                CASE WHEN t.user1_id = ? THEN t.user2_id ELSE t.user1_id END as andere_id,
                (SELECT inhoud FROM chat_messages WHERE thread_id = t.id ORDER BY created_at DESC LIMIT 1) as laatste_bericht,
                (SELECT created_at FROM chat_messages WHERE thread_id = t.id ORDER BY created_at DESC LIMIT 1) as laatste_tijd
            FROM chat_threads t
            JOIN users u1 ON t.user1_id = u1.id
            JOIN users u2 ON t.user2_id = u2.id
            WHERE t.user1_id = ? OR t.user2_id = ?
            ORDER BY laatste_tijd DESC
        ", [$myId, $myId, $myId, $myId, $myId]);

        return response()->json($threads);
    }

    public function directThread(Request $request, string $userId)
    {
        $myId = $request->session()->get('userId');
        if ($userId === $myId) return response()->json(['error' => 'Je kunt geen gesprek met jezelf starten'], 400);

        $thread = ChatThread::where(function ($q) use ($myId, $userId) {
            $q->where('user1_id', $myId)->where('user2_id', $userId);
        })->orWhere(function ($q) use ($myId, $userId) {
            $q->where('user1_id', $userId)->where('user2_id', $myId);
        })->first();

        if (!$thread) {
            $thread = ChatThread::create([
                'user1_id' => $myId,
                'user2_id' => $userId,
            ]);
        }

        $otherUser = User::select('id', 'naam', 'kleur')->find($userId);
        $result = $thread->toArray();
        $result['andere_naam'] = $otherUser?->naam;
        $result['andere_kleur'] = $otherUser?->kleur;
        $result['andere_id'] = $userId;

        return response()->json($result);
    }

    public function threadMessages(Request $request, string $threadId)
    {
        $thread = ChatThread::find($threadId);
        if (!$thread) return response()->json(['error' => 'Gesprek niet gevonden'], 404);

        $myId = $request->session()->get('userId');
        if ($thread->user1_id !== $myId && $thread->user2_id !== $myId) {
            return response()->json(['error' => 'Niet toegestaan'], 403);
        }

        $limit = (int) ($request->query('limit', 50));
        $before = $request->query('before');

        $query = ChatMessage::select('chat_messages.*', 'u.naam as afzender_naam', 'u.kleur as afzender_kleur')
            ->join('users as u', 'chat_messages.afzender_id', '=', 'u.id')
            ->where('chat_messages.thread_id', $threadId);

        if ($before) $query->where('chat_messages.created_at', '<', $before);

        $messages = $query->orderByDesc('chat_messages.created_at')->limit($limit)->get();
        return response()->json($messages->reverse()->values());
    }

    public function sendThreadMessage(Request $request, string $threadId)
    {
        $inhoud = trim($request->inhoud ?? '');
        if (!$inhoud) return response()->json(['error' => 'Bericht is leeg'], 400);

        $thread = ChatThread::find($threadId);
        if (!$thread) return response()->json(['error' => 'Gesprek niet gevonden'], 404);

        $myId = $request->session()->get('userId');
        if ($thread->user1_id !== $myId && $thread->user2_id !== $myId) {
            return response()->json(['error' => 'Niet toegestaan'], 403);
        }

        $message = ChatMessage::create([
            'thread_id' => $threadId,
            'afzender_id' => $myId,
            'inhoud' => $inhoud,
        ]);

        $otherId = $thread->user1_id === $myId ? $thread->user2_id : $thread->user1_id;
        Notification::create([
            'user_id' => $otherId,
            'type' => 'direct_message',
            'message_id' => $message->id,
            'thread_id' => $threadId,
        ]);

        return response()->json(
            ChatMessage::select('chat_messages.*', 'u.naam as afzender_naam', 'u.kleur as afzender_kleur')
                ->join('users as u', 'chat_messages.afzender_id', '=', 'u.id')
                ->where('chat_messages.id', $message->id)->first()
        );
    }

    // ========== POLLING ==========

    public function poll(Request $request)
    {
        $myId = $request->session()->get('userId');
        $since = $request->query('since', now()->subMinute()->toIso8601String());

        $channelMessages = DB::select("
            SELECT m.*, u.naam as afzender_naam, u.kleur as afzender_kleur,
                c.naam as channel_naam, c.id as channel_id, 'channel' as bericht_type
            FROM chat_messages m
            JOIN users u ON m.afzender_id = u.id
            JOIN chat_channels c ON m.channel_id = c.id
            WHERE m.created_at > ? AND m.afzender_id != ?
            ORDER BY m.created_at ASC LIMIT 50
        ", [$since, $myId]);

        $dmMessages = DB::select("
            SELECT m.*, u.naam as afzender_naam, u.kleur as afzender_kleur,
                t.id as thread_id, 'dm' as bericht_type
            FROM chat_messages m
            JOIN users u ON m.afzender_id = u.id
            JOIN chat_threads t ON m.thread_id = t.id
            WHERE m.created_at > ? AND m.afzender_id != ?
                AND (t.user1_id = ? OR t.user2_id = ?)
            ORDER BY m.created_at ASC LIMIT 50
        ", [$since, $myId, $myId, $myId]);

        $unreadCount = Notification::where('user_id', $myId)->where('gelezen', false)->count();

        return response()->json([
            'channelMessages' => $channelMessages,
            'dmMessages' => $dmMessages,
            'unreadCount' => $unreadCount,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
