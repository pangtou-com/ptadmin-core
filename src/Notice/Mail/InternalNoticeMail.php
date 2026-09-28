<?php

declare(strict_types=1);

namespace PTAdmin\Notice\Mail;

use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

final class InternalNoticeMail extends Mailable
{
    public function __construct(
        public readonly string $noticeSubject,
        public readonly string $htmlBody,
        public readonly string $textBody,
    ) {
    }

    public function build(): self
    {
        $this->subject($this->noticeSubject)->html($this->htmlBody);
        if ($this->textBody !== '') {
            $textBody = $this->textBody;
            $this->withSymfonyMessage(static function (Email $message) use ($textBody): void {
                $message->text($textBody);
            });
        }

        return $this;
    }
}
