<?php

namespace Tests\Support;

use App\Models\ChannelAccount;
use App\Services\Email\MerchantSmtpTransportFactory;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * خادم SMTP وهميّ للاختبارات: لا شبكة. يسجّل ما فُحص وما أُرسل، ويرمي ما
 * يُطلب منه (رفض الدخول، رفض المستلم، انقطاع مؤقّت) بالترتيب.
 */
class FakeSmtpTransportFactory extends MerchantSmtpTransportFactory
{
    /** @var list<array> */
    public array $verified = [];

    /** @var list<array> */
    public array $made = [];

    public ?\Throwable $verifyFailure = null;

    /** نتائج الإرسال بالترتيب: null = نجاح، أو استثناء يُرمى. ما بعدها نجاح. */
    public array $sendOutcomes = [];

    public ArrayTransport $mailbox;

    public function __construct()
    {
        // لا حارس ولا شبكة.
        $this->mailbox = new ArrayTransport();
    }

    public function make(array $smtp): TransportInterface
    {
        $this->made[] = $smtp;

        return new class($this) implements TransportInterface {
            public function __construct(private FakeSmtpTransportFactory $factory)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $outcome = array_shift($this->factory->sendOutcomes);

                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return $this->factory->mailbox->send($message, $envelope);
            }

            public function stop(): void
            {
            }

            public function __toString(): string
            {
                return 'fake-smtp';
            }
        };
    }

    public function forAccount(ChannelAccount $account): TransportInterface
    {
        return $this->make([
            'host' => (string) $account->credential('host'),
            'port' => (int) $account->credential('port'),
            'username' => (string) $account->credential('username'),
            'password' => (string) $account->credential('password'),
        ]);
    }

    public function verify(array $smtp): void
    {
        $this->verified[] = $smtp;

        if ($this->verifyFailure) {
            throw $this->verifyFailure;
        }
    }

    /** @return list<\Symfony\Component\Mime\Email> */
    public function sent(): array
    {
        return $this->mailbox->messages()->map(fn ($sent) => $sent->getOriginalMessage())->values()->all();
    }

    public static function rejection(string $message, int $code): TransportException
    {
        return new TransportException($message, $code);
    }
}
