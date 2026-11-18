@extends('layouts.app')
@section('title', 'Privacy activity')
@section('body_class', 'privacy-audit-page')
@section('content')
@php
    $actions = ['room_photo_sent_to_gemini' => 'Gemini processing requested', 'room_photo_gemini_consent_granted' => 'AI consent granted', 'room_photo_gemini_consent_revoked' => 'AI consent withdrawn', 'room_photo_deleted' => 'Room photo deleted', 'room_photo_deleted_with_account' => 'Photo removed with account', 'room_photo_retention_expired' => 'Photo retention expired', 'customer_account_deleted' => 'Customer account deleted'];
    $purposes = ['room_analysis' => 'Analyze the room photo', 'room_preview_v2_analysis' => 'Analyze the room for a preview', 'room_preview_v2_generation' => 'Generate a furnished room preview', 'room_preview_v2_verification' => 'Check the generated room preview', 'furnished_preview' => 'Generate a furnished room preview'];
    $timezone = config('app.timezone', 'UTC');
@endphp
<header class="page-heading"><div><p class="section-kicker">Privacy controls</p><h1>Room-photo activity</h1><p>Review AI consent, processing requests, photo deletions, and retention events.</p></div></header>
<section class="privacy-activity" aria-labelledby="activity-heading">
    <div class="privacy-activity-heading"><div><h2 id="activity-heading">Activity history</h2><p>Newest first · Times shown in {{ $timezone }}</p></div><span class="privacy-event-count">{{ number_format($events->total()) }} {{ Str::plural('event', $events->total()) }}</span></div>
    <p class="privacy-activity-note">Processing entries identify requests to the selected AI provider. Historical Gemini entries remain labeled Gemini. They do not confirm that processing completed successfully.</p>
    @if($events->isNotEmpty())
    <table class="table privacy-activity-table">
        <caption class="visually-hidden">Recorded privacy activity, newest first</caption>
        <thead><tr><th scope="col">Date &amp; time</th><th scope="col">Activity</th><th scope="col">Account &amp; record</th><th scope="col">Details</th></tr></thead>
        <tbody>
        @foreach($events as $event)
            @php
                $metadata = $event->metadata ?? [];
                $purpose = $metadata['purpose'] ?? null;
                $when = $event->created_at->copy()->timezone($timezone);
            @endphp
            <tr>
                <td data-label="Date & time"><time datetime="{{ $when->toIso8601String() }}"><span>{{ $when->format('M j, Y') }}</span><small>{{ $when->format('g:i A') }}</small></time></td>
                <td data-label="Activity"><strong class="privacy-action">{{ $actions[$event->action] ?? Str::headline($event->action) }}</strong></td>
                <td data-label="Account & record"><span>{{ $event->user?->name ?? 'Account unavailable or system event' }}</span><small class="privacy-record">@if($event->subject_type){{ match($event->subject_type) { 'room_image' => 'Room photo', 'user' => 'Account', default => Str::headline($event->subject_type) } }}@if($event->subject_id !== null) #{{ $event->subject_id }}@endif @else No linked record @endif</small></td>
                <td data-label="Details">
                    <p class="privacy-detail-summary">
                        @if(is_string($purpose))
                            {{ $purposes[$purpose] ?? Str::headline($purpose) }}
                        @elseif(isset($metadata['retention_days']))
                            Retention period: {{ $metadata['retention_days'] }} days
                        @elseif(isset($metadata['consent_version']))
                            Consent version: {{ $metadata['consent_version'] }}
                        @else
                            No additional details recorded.
                        @endif
                    </p>
                    @if($metadata)
                    <details class="privacy-recorded-fields"><summary>Recorded fields</summary><dl>
                        @foreach($metadata as $key => $value)
                            <div><dt>{{ Str::headline($key) }}</dt><dd>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) }}</dd></div>
                        @endforeach
                    </dl></details>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @else
    <div class="empty-state"><h3>No privacy activity yet</h3><p>Recorded photo consent, processing, and deletion events will appear here.</p></div>
    @endif
</section>
<div class="mt-3">{{ $events->links() }}</div>
@endsection
