<?php

namespace App\Services\Aws;

use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sqs\SqsClient;

/**
 * @codeCoverageIgnore
 */
class Sqs
{
    /**
     * @var SqsClient
     */
    private $client;

    private $queueUrl;

    private $messages;

    public array $options = [
        'profile' => 'insight_sqs_production',
        'region' => 'us-east-2',
        'version' => '2012-11-05',
    ];

    protected int $maxNumberOfMessages = 1;

    public function __construct($config = [], $queueUrl = null)
    {
        $this->options = \array_merge($this->options, \Arr::only($config, ['profile', 'region', 'version', 'retries']));
        $this->client = new SqsClient(config: $this->options);
        $this->queueUrl = $queueUrl;
    }

    public function getMessages()
    {
        if (empty($this->messages)) {
            $this->receiveMessage();
        }

        return $this->messages;
    }

    protected function receiveMessage()
    {
        try {
            $result = $this->client->receiveMessage([
                'AttributeNames' => ['SentTimestamp'],
                'MaxNumberOfMessages' => $this->maxNumberOfMessages,
                'MessageAttributeNames' => ['All'],
                'QueueUrl' => $this->queueUrl, // REQUIRED
                'WaitTimeSeconds' => 15,
            ]);

            $this->messages = $result->get('Messages');

        } catch (AwsException $e) {
            // output error message if fails
            error_log($e->getMessage());
        }

        return $this->messages;
    }

    public function deleteMessage(string $ReceiptHandle): Result
    {
        return $this->client->deleteMessage([
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => $ReceiptHandle,
        ]);
    }

    public function setMaxNumberOfMessages(int $maxNumberOfMessages): self
    {
        $this->maxNumberOfMessages = $maxNumberOfMessages;

        return $this;
    }

    public function getMaxNumberOfMessages(): int
    {
        return $this->maxNumberOfMessages;
    }

    public function getQueueUrl(): string
    {
        return $this->queueUrl;
    }

    public function setQueueUrl(string $queueUrl): self
    {
        $this->queueUrl = $queueUrl;

        return $this;
    }

    public function sendClearplanMessage(string $body, array $messageAttributes = [], string $messageGroupId = 'default', ?string $messageDeduplicationId = null): Result
    {
        $params = [
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => \json_encode([
                'Message' => $body,
                'MessageAttributes' => $messageAttributes,
            ]),
        ];

        if ($messageGroupId) {
            $params['MessageGroupId'] = $messageGroupId;
        }

        if ($messageDeduplicationId) {
            $params['MessageDeduplicationId'] = $messageDeduplicationId;
        }

        return $this->client->sendMessage($params);
    }

    public function sendRdnMetricsMessage(string $body): Result
    {
        return $this->client->sendMessage([
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $body,
        ]);
    }
}
