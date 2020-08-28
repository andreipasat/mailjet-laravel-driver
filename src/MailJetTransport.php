<?php

namespace MailjetLaravelDriver;

use Illuminate\Support\Facades\Session;
use Swift_Mime_SimpleMessage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Mail\Transport\Transport;
use Illuminate\Support\Facades\Log;
use \Mailjet\Resources;

class MailJetTransport extends Transport {

    private $userName;
    private $secretKey;
    private $headers;

    /**
     * Create a new preview transport instance.
     *
     * @param  string $userName
     * @param  string $secretKey
     *
     * @return void
     */
    public function __construct($userName,$secretKey) {
        $this->userName = $userName;
        $this->secretKey = $secretKey;
        $this->headers = [];
    }


    private function getTo(Swift_Mime_SimpleMessage $message)
    {
        $to = [];
        if ($message->getTo()) {
            $to = array_merge($to, array_keys($message->getTo()));
        }

        if ($message->getCc()) {
            $to = array_merge($to, array_keys($message->getCc()));
        }

        if ($message->getBcc()) {
            $to = array_merge($to, array_keys($message->getBcc()));
        }
        return $to;
    }
    
    private function getReplyTo(Swift_Mime_SimpleMessage $message) {
        $replyTo = $message->getReplyTo();
        if ($replyTo) {
            $email = array_key_first($replyTo);
            return [
                'Email' => $email,
                'Name' => $replyTo[$email]
            ];
        }
        return [];
    }

    /**
     * Gets all the headers from the message
     * @param $message
     * @return array
     */
    private function getHeaders($message)
    {
        $this->headers = $message->getHeaders()->getAll();
        return $this->headers;
    }

    /**
     * Gets a custom header by field name
     * @param $fieldname
     * @return null
     */
    private function getCustomHeader($fieldname)
    {
        foreach($this->headers as $h)
        {
            if(get_class($h) == 'Swift_Mime_Headers_UnstructuredHeader' && $h->getFieldName() == $fieldname)
            {
                return $h->getValue();
            }
        }

        return null;
    }

    /**
     * Adds attachment to the message
     *
     * @param Swift_Mime_SimpleMessage $message
     * @return array
     */
    private function addAttachments(Swift_Mime_SimpleMessage $message): array
    {
        $attachments = [];
        if (count($children = $message->getChildren()) > 0) {
            $i = 0;
            foreach ($children as $child) {
                if ($i++ == 0 && $child->getContentType() == 'text/plain') {
                    $textpart = $child->getBody();
                    continue;
                }
                $newattachment = [];
                $newattachment['ContentType']   = $child->getContentType();
                $newattachment['Filename']      = $child->getFilename();
                $newattachment['Base64Content'] = base64_encode($child->getBody());
                array_push($attachments, $newattachment);
            }
        }

        return $attachments;
    }

    /**
     * {@inheritdoc}
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null) {
        $headers = $this->getHeaders($message);
        
        //to emails
        $toEmailsOnly = $this->getTo($message);
        $to = [];
        foreach ($toEmailsOnly as $t) {
            $to[] = [
                "Email" => $t
            ];
        }
        
        //reply to emails
        $replyTo = $this->getReplyTo($message);
        
        $mj = new \Mailjet\Client(
            $this->userName,
            $this->secretKey,
            true,
            ['version' => 'v3.1']
        );

        $attachments = $this->addAttachments($message);

        $body = [
            'Messages' => [
                [
                    'From' => [
                        'Email' => array_keys($message->getFrom())[0],
                        'Name' => array_values($message->getFrom())[0]
                    ],
                    'To' => $to,
                    'Subject' => $message->getSubject(),
                    'TextPart' => $message->getBody(),
                    'HTMLPart' => $message->getBody(),
                    'Attachments' => $attachments,
                ]
            ]
        ];
        
        if (!empty($replyTo)) {
            $body['Messages'][0]['ReplyTo'] = $replyTo;
        }

        $campaign = $this->getCustomHeader('X-Mailjet-Campaign');
        if (!is_null($campaign)) {
            $body['Messages'][0]['CustomCampaign'] = $campaign;
        }

        $template = $this->getCustomHeader('X-MailjetLaravel-Template');
        if (!is_null($template)) {
            $body['Messages'][0]['TemplateLanguage'] = true;
            $body['Messages'][0]['TemplateID'] = $template;
            $body['Messages'][0]['Variables'] = json_decode($this->getCustomHeader('X-MailjetLaravel-TemplateBody'));

            //unset
            unset($body['Messages'][0]['HTMLPart']);
            unset($body['Messages'][0]['TextPart']);
        }

        $response = $mj->post(Resources::$Email, ['body' => $body]);
        if ($response->getStatus() == 200) {
            $result = $response->getBody();
        } else {
            $result = $response->getBody();
            Log::error('Mailjet Error: ' . json_encode($result));
        }
        return $result;
    }

}
