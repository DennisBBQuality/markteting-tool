<?php

namespace App\Jobs;

use App\Mail\AssignmentMail;
use App\Models\PitboardNotification;
use App\Models\User;
use App\Services\AssignmentNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAssignmentNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $notificationId) {}

    public function handle(AssignmentNotifications $service): void
    {
        // Claim once. After an ambiguous transport failure never retry automatically.
        if (! PitboardNotification::whereKey($this->notificationId)->where('email_status', 'pending')
            ->update(['email_status' => 'processing', 'email_attempted_at' => now()])) {
            return;
        }
        $notification = PitboardNotification::findOrFail($this->notificationId);
        $recipient = User::find($notification->user_id);
        $table = $notification->kind === 'task' ? 'taak_gebruiker' : 'project_gebruiker';
        $assigned = DB::table($table)->where($notification->kind.'_id', $notification->target_id)->where('user_id', $notification->user_id)->exists();
        if (! $recipient?->actief || ! $assigned || ! $service->emailReady() || ! $service->wantsEmail($notification->user_id, $notification->kind)) {
            $notification->update(['email_status' => 'cancelled']);

            return;
        }
        try {
            Mail::mailer(config('pitboard_notifications.mailer'))->to($recipient->email)->send(new AssignmentMail($notification));
            // SMTP acceptance is not proof of delivery to the recipient's inbox.
            $notification->update(['email_status' => 'accepted', 'email_accepted_at' => now()]);
        } catch (Throwable) {
            $notification->update(['email_status' => 'uncertain']);
        }
    }
}
