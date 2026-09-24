<!doctype html>
<html lang="nl"><body style="font-family:Arial,sans-serif;color:#301b12;line-height:1.6">
<h1 style="font-size:24px">BBQuality Pitboard</h1>
<p>{{ $notification->actor_name }} heeft {{ $notification->kind === 'task' ? 'een taak aan je toegewezen' : 'je toegevoegd aan een project' }}.</p>
<h2 style="font-size:20px">{{ $notification->title }}</h2>
@if ($notification->deadline)
<p>Deadline: {{ $notification->deadline->format('d-m-Y') }}</p>
@endif
<p><a href="{{ $link }}" style="background:#ef454b;color:#fff;padding:12px 20px;display:inline-block;border-radius:6px;text-decoration:none">{{ $notification->kind === 'task' ? 'Bekijk taak' : 'Bekijk project' }}</a></p>
<p style="font-size:13px">Je ontvangt deze melding omdat je aan dit onderdeel bent gekoppeld. Je e-mailvoorkeuren wijzig je in BBQuality Pitboard via Meldingen → Voorkeuren.</p>
</body></html>
