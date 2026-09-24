<?php

namespace App\Services;

use App\Jobs\SendAssignmentNotification;
use App\Models\PitboardNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AssignmentNotifications
{
    public function emailReady(): bool
    {
        $mailer = config('pitboard_notifications.mailer');

        return app()->environment(['production', 'testing'])
            && config('pitboard_notifications.email_enabled')
            && filter_var(config('pitboard_notifications.from_address'), FILTER_VALIDATE_EMAIL)
            && str_starts_with((string) config('pitboard_notifications.url'), 'https://')
            && config("mail.mailers.$mailer.transport") === 'smtp';
    }

    public function wantsEmail(string $userId, string $kind): bool
    {
        $preferences = DB::table('pitboard_notification_preferences')->where('user_id', $userId)->first();

        return (bool) ($preferences->{$kind.'_email'} ?? true);
    }

    /** Called inside the parent record's transaction; its row lock serializes assignment changes. */
    public function sync(Model $target, Request $request, string $kind): void
    {
        $field = $kind === 'task' ? 'toegewezen_aan' : 'medewerkers';
        if (! $request->exists($field)) {
            return;
        }
        $value = $request->input($field);
        $ids = is_string($value) ? ($value === '' ? [] : [$value]) : ($value ?? []);
        Validator::make(['ids' => $ids], ['ids' => 'array', 'ids.*' => 'required|string|max:255|exists:users,id'])->validate();
        $ids = array_values(array_unique($ids));
        $table = $kind === 'task' ? 'taak_gebruiker' : 'project_gebruiker';
        $key = $kind.'_id';
        $previous = DB::table($table)->where($key, $target->id)->pluck('user_id')->all();
        DB::table($table)->where($key, $target->id)->whereNotIn('user_id', $ids)->delete();
        $added = array_diff($ids, $previous);
        $actor = User::findOrFail($request->session()->get('userId'));
        foreach ($added as $userId) {
            DB::table($table)->insert(['id' => (string) Str::uuid(), $key => $target->id, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $recipient = User::find($userId);
            if ($userId === $actor->id || ! $recipient?->actief) {
                continue;
            }
            $emailStatus = ! $this->wantsEmail($userId, $kind) ? 'opted_out' : ($this->emailReady() ? 'pending' : 'disabled');
            $notification = PitboardNotification::create([
                'user_id' => $userId, 'kind' => $kind, 'target_id' => $target->id,
                'title' => Str::limit($kind === 'task' ? $target->titel : $target->naam, 250, '…'),
                'actor_name' => $actor->naam, 'deadline' => $target->deadline,
                'email_status' => $emailStatus,
            ]);
            if ($emailStatus === 'pending') {
                DB::afterCommit(fn () => SendAssignmentNotification::dispatch($notification->id)->onConnection('deferred'));
            }
        }
    }
}
