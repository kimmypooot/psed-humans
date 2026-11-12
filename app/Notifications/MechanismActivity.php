<?php

namespace App\Notifications;

use App\Models\HrMechanism;
use Illuminate\Notifications\Notification;

/**
 * An in-app notice (the bell and the notifications page) about something that happened to an HR
 * mechanism and concerns the person: a new submission to route, one routed to them, a deadline.
 * It is not emailed: the agency has its own emails, and staff already have the dashboard.
 *
 * `kind` says what happened, `category` how to group it: `action` (needs you), `progress` (moved
 * along) or `deadline` (the 20-day turnaround).
 */
class MechanismActivity extends Notification
{
    /** kind => [category, tone] */
    public const KINDS = [
        'submitted' => ['action', 'info'],
        'resubmitted' => ['action', 'info'],
        'routed' => ['action', 'info'],
        'forwarded' => ['action', 'info'],
        'reviewed' => ['action', 'success'],
        'marked_for_compliance' => ['action', 'warning'],
        'returned_to_officer' => ['action', 'warning'],
        'returned' => ['progress', 'danger'],
        'approved' => ['progress', 'success'],
        'due_soon' => ['deadline', 'warning'],
        'overdue' => ['deadline', 'danger'],
    ];

    public function __construct(public HrMechanism $mechanism, public string $kind, public string $title, public string $body) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        [$category, $tone] = self::KINDS[$this->kind] ?? ['progress', 'info'];

        return [
            'mechanism_id' => $this->mechanism->id,
            'tracking_code' => $this->mechanism->tracking_code,
            'kind' => $this->kind,
            'category' => $category,
            'tone' => $tone,
            'title' => $this->title,
            'body' => $this->body,
            'url' => '/mechanisms?open='.$this->mechanism->id,
        ];
    }
}
