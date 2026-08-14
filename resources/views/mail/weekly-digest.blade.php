{{--
    Task 33's weekly digest body — see App\Mail\WeeklyDigestMail and
    App\Services\Mail\DigestService for how $summary is built and scoped.
    Plain HTML, no markdown mail component: this project has none of
    Laravel's published markdown mail views, and a hand-rolled table is
    all three sections need. Every date is rendered in $recipientTimezone
    (the recipient's own profile timezone, the same one DigestService used
    to decide the digest was due), never the server's UTC.
--}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: -apple-system, Helvetica, Arial, sans-serif; color: #1f2933; background: #f4f5f7; margin: 0; padding: 24px;">
    <table role="presentation" width="100%" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 24px;">
        <tr>
            <td>
                <p>{{ __('mail.weekly_digest.greeting', ['name' => $recipientName]) }}</p>
                <p>{{ __('mail.weekly_digest.intro') }}</p>

                <h3>{{ __('mail.weekly_digest.published_heading') }}</h3>
                @if ($summary->published->isEmpty())
                    <p style="color: #6b7280;">{{ __('mail.weekly_digest.published_empty') }}</p>
                @else
                    <ul>
                        @foreach ($summary->published->items as $group)
                            <li>
                                {{ $group->organization->name }}
                                &mdash;
                                {{ $group->publish_date?->clone()->setTimezone($recipientTimezone)->locale(app()->getLocale())->translatedFormat('D, d M Y H:i') }}
                            </li>
                        @endforeach
                    </ul>
                    @if ($summary->published->hasMore())
                        <p style="color: #6b7280;">{{ __('mail.weekly_digest.more', ['count' => $summary->published->moreCount()]) }}</p>
                    @endif
                @endif

                <h3>{{ __('mail.weekly_digest.failed_heading') }}</h3>
                @if ($summary->failed->isEmpty())
                    <p style="color: #6b7280;">{{ __('mail.weekly_digest.failed_empty') }}</p>
                @else
                    <ul>
                        @foreach ($summary->failed->items as $group)
                            <li>
                                {{ $group->organization->name }}
                                &mdash;
                                {{-- notified_at, not publish_date: DigestService::fetchSection() also
                                     WINDOWS this section by notified_at (see its docblock for why
                                     publish_date can be arbitrarily stale by the time a group finally
                                     settles to ERROR) -- displaying publish_date here would show a date
                                     outside the "this week" framing the heading promises. --}}
                                {{ $group->notified_at?->clone()->setTimezone($recipientTimezone)->locale(app()->getLocale())->translatedFormat('D, d M Y H:i') }}
                                <br>
                                <span style="color: #b91c1c;">{{ $group->error }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($summary->failed->hasMore())
                        <p style="color: #6b7280;">{{ __('mail.weekly_digest.more', ['count' => $summary->failed->moreCount()]) }}</p>
                    @endif
                @endif

                <h3>{{ __('mail.weekly_digest.scheduled_heading') }}</h3>
                @if ($summary->scheduled->isEmpty())
                    <p style="color: #6b7280;">{{ __('mail.weekly_digest.scheduled_empty') }}</p>
                @else
                    <ul>
                        @foreach ($summary->scheduled->items as $group)
                            <li>
                                {{ $group->organization->name }}
                                &mdash;
                                {{ $group->publish_date?->clone()->setTimezone($recipientTimezone)->locale(app()->getLocale())->translatedFormat('D, d M Y H:i') }}
                            </li>
                        @endforeach
                    </ul>
                    @if ($summary->scheduled->hasMore())
                        <p style="color: #6b7280;">{{ __('mail.weekly_digest.more', ['count' => $summary->scheduled->moreCount()]) }}</p>
                    @endif
                @endif

                <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">
                <p style="color: #9ca3af; font-size: 12px;">{{ __('mail.weekly_digest.footer') }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
