<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Notifier\Bridge\JustCall;

use Symfony\Component\Notifier\Exception\InvalidArgumentException;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Exception\UnsupportedMessageTypeException;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\AbstractTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class JustCallTransport extends AbstractTransport
{
    protected const HOST = 'api.justcall.io';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $defaultFrom,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
    )
    {
        parent::__construct($client, $dispatcher);
    }

    public function __toString(): string
    {
        return sprintf('justCall://%s?from=%s', $this->getEndpoint(), $this->defaultFrom);
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof SmsMessage;
    }

    protected function doSend(MessageInterface $message): SentMessage
    {
        if (!$message instanceof SmsMessage) {
            throw new UnsupportedMessageTypeException(__CLASS__, SmsMessage::class, $message);
        }

        if (!preg_match('/^\+[1-9]\d{1,14}$/', $from = $message->getFrom() ?: '+' . $this->defaultFrom)) {
            throw new InvalidArgumentException(sprintf('The "From" number "%s" is not an valid E.164 number', $from));
        }

        $endpoint = sprintf('https://%s/v2/texts/new', $this->getEndpoint());
        $response = $this->client->request('POST', $endpoint, [
            'auth_basic' => $this->apiKey . ':' . $this->apiSecret,
            'headers' => [
                'Authorization' => "{$this->apiKey}:{$this->apiSecret}",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => (string)json_encode([
                'justcall_number' => $from,
                'contact_number' => $message->getPhone(),
                'body' => $message->getSubject(),
            ]),
        ]);

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('Could not reach the remote JustCall server.', $response, 0, $e);
        }

        if (!in_array($statusCode, [200, 201])) {
            $error = $response->toArray(false);

            throw new TransportException('Unable to send the SMS: [' . $statusCode . '] ' . json_encode($error), $response);
        }

        $success = $response->toArray(false);

        $sentMessage = new SentMessage($message, (string)$this);
        $sentMessage->setMessageId($success['data'][0]['id']);

        return $sentMessage;
    }
}
