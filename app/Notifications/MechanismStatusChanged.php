<?php

namespace App\Notifications;

use App\Models\HrMechanism;
use App\Models\HrMechanismAttachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Emails the address an agency left that its HR mechanism moved along, with the link to its
 * tracking page. The link is signed: whoever holds it can see that one mechanism, nothing else.
 * `$attachments` are the reviewed HR mechanism, the transmittal and the HR checklist, attached to the email.
 */
class MechanismStatusChanged extends Notification
{
    /**
     * @param  list<string>  $lines  Extra paragraphs after the first.
     * @param  iterable<HrMechanismAttachment>  $attachments
     */
    public function __construct(
        public HrMechanism $mechanism,
        public string $headline,
        public string $body,
        public array $lines = [],
        public iterable $attachments = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->mechanism->title ?: $this->mechanism->mechanism_type->value;

        $mail = (new MailMessage)
            ->subject($this->headline.': '.$label.' ('.$this->mechanism->tracking_code.')')
            ->greeting($this->headline)
            ->line($this->mechanism->agency->name.' · '.$label)
            ->line($this->body);

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        $names = [];
        foreach ($this->attachments as $file) {
            $mail->attachFromStorageDisk('local', $file->path, $file->name);
            $names[] = $file->label().': '.$file->name;
        }

        if ($names !== []) {
            $mail->line('Attached: '.implode('; ', $names).'.');
        }

        return $mail
            ->line('Tracking code: '.$this->mechanism->tracking_code)
            ->action('Track this HR mechanism', self::trackingUrl($this->mechanism))
            ->line('Keep this email. The link above is the only way to see the status and answer a request for compliance.');
    }

    /** The permanent signed link to the tracking page of a mechanism. */
    public static function trackingUrl(HrMechanism $mechanism): string
    {
        return URL::signedRoute('track.show', $mechanism);
    }
}
