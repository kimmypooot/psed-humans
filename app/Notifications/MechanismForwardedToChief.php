<?php

namespace App\Notifications;

use App\Models\HrMechanism;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the PSED Chief that an Action Officer forwarded a reviewed HR mechanism and its HR checklist, so
 * it is waiting for their decision (for approval or for compliance). It shows in the bell of the
 * system and is emailed; the documents are opened in the system, not attached to the email.
 */
class MechanismForwardedToChief extends Notification
{
    public function __construct(public HrMechanism $mechanism, public ?User $forwardedBy = null) {}

    /**
     * The bell first, so a mail server that is down never costs the Chief the in-app notice.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $by = $this->forwardedBy?->name ?? 'The Action Officer';
        $names = $this->mechanism->currentAttachments()->whereIn('kind', ['reviewed', 'checklist'])->map(fn ($f) => $f->label().': '.$f->name)->all();

        $mail = (new MailMessage)
            ->subject('For your review: '.$this->label().' ('.$this->mechanism->tracking_code.')')
            ->greeting('For your review')
            ->line("{$by} forwarded a reviewed HR mechanism to you.")
            ->line($this->mechanism->agency->name.' · '.$this->label().' · '.$this->mechanism->tracking_code);

        if ($names !== []) {
            $mail->line('Forwarded: '.implode('; ', $names).'.');
        }

        return $mail
            ->line('Open it to read the reviewed HR mechanism and the HR checklist, then decide: for approval or for compliance. The transmittal is made from that decision.')
            ->action('Review it', url($this->path()));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'mechanism_id' => $this->mechanism->id,
            'tracking_code' => $this->mechanism->tracking_code,
            'kind' => 'forwarded',
            'category' => 'action',
            'tone' => 'info',
            'title' => 'For your review',
            'body' => $this->mechanism->agency->name.' · '.$this->label().' was forwarded by '.($this->forwardedBy?->name ?? 'the Action Officer').'.',
            'url' => $this->path(),
        ];
    }

    /** Opens the list with this mechanism's details already showing. */
    private function path(): string
    {
        return '/mechanisms?open='.$this->mechanism->id;
    }

    private function label(): string
    {
        return $this->mechanism->title ?: $this->mechanism->mechanism_type->value;
    }
}
