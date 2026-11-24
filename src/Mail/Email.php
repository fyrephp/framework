<?php
declare(strict_types=1);

namespace Fyre\Mail;

use finfo;
use Fyre\Core\Config;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use InvalidArgumentException;
use RuntimeException;

use function addcslashes;
use function array_column;
use function array_filter;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_pop;
use function base64_encode;
use function chunk_split;
use function count;
use function date;
use function explode;
use function file_get_contents;
use function filter_var;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function mb_convert_encoding;
use function mb_encode_mimeheader;
use function md5;
use function preg_match;
use function preg_split;
use function random_bytes;
use function rtrim;
use function sprintf;
use function str_replace;
use function strlen;
use function time;
use function wordwrap;

use const DATE_RFC2822;
use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_EMAIL;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Represents an email message.
 */
class Email
{
    use DebugTrait;
    use MacroTrait;

    public const BOTH = 'both';

    public const HTML = 'html';

    public const TEXT = 'text';

    protected string|null $appCharset = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $attachments = [];

    /**
     * @var array<string, string>
     */
    protected array $bcc = [];

    /**
     * @var array<string, string>
     */
    protected array $body = [];

    protected string $boundary;

    /**
     * @var array<string, string>
     */
    protected array $cc = [];

    protected string $charset = 'utf-8';

    protected string $format = self::TEXT;

    /**
     * @var array<string, string>
     */
    protected array $from = [];

    /**
     * @var array<string, string|string[]>
     */
    protected array $headers = [];

    protected string $messageId;

    protected int|null $priority = null;

    /**
     * @var array<string, string>
     */
    protected array $readReceipt = [];

    /**
     * @var array<string, string>
     */
    protected array $replyTo = [];

    /**
     * @var array<string, string>
     */
    protected array $returnPath = [];

    /**
     * @var array<string, string>
     */
    protected array $sender = [];

    protected string $subject = '';

    /**
     * @var array<string, string>
     */
    protected array $to = [];

    /**
     * Constructs an Email.
     *
     * @param Mailer $mailer The Mailer.
     * @param Config $config The Config.
     */
    public function __construct(
        protected Mailer $mailer,
        Config $config
    ) {
        $this->charset = $mailer->getConfig()['charset'] ?? 'utf-8';
        $this->appCharset = $config->get('App.charset');
    }

    /**
     * Adds attachments.
     *
     * @param array<string, array<string, mixed>> $attachments The attachments.
     * @return static The Email instance.
     */
    public function addAttachments(array $attachments): static
    {
        foreach ($attachments as $filename => $attachment) {
            $this->attachments[$filename] = $attachment;
        }

        return $this;
    }

    /**
     * Adds a BCC address.
     *
     * @param string $email The email address.
     * @param string|null $name The name.
     * @return static The Email instance.
     */
    public function addBcc(string $email, string|null $name = null): static
    {
        $email = static::validateEmail($email);

        if ($email) {
            $this->bcc[$email] = $name ?? $email;
        }

        return $this;
    }

    /**
     * Adds a CC address.
     *
     * @param string $email The email address.
     * @param string|null $name The name.
     * @return static The Email instance.
     */
    public function addCc(string $email, string|null $name = null): static
    {
        $email = static::validateEmail($email);

        if ($email) {
            $this->cc[$email] = $name ?? $email;
        }

        return $this;
    }

    /**
     * Adds a reply-to address.
     *
     * @param string $email The email address.
     * @param string|null $name The name.
     * @return static The Email instance.
     */
    public function addReplyTo(string $email, string|null $name = null): static
    {
        $email = static::validateEmail($email);

        if ($email) {
            $this->replyTo[$email] = $name ?? $email;
        }

        return $this;
    }

    /**
     * Adds a to address.
     *
     * @param string $email The email address.
     * @param string|null $name The name.
     * @return static The Email instance.
     */
    public function addTo(string $email, string|null $name = null): static
    {
        $email = static::validateEmail($email);

        if ($email) {
            $this->to[$email] = $name ?? $email;
        }

        return $this;
    }

    /**
     * Returns the attachments.
     *
     * @return array<string, array<string, mixed>> The attachments.
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * Returns the BCC addresses.
     *
     * @return array<string, string> The BCC addresses.
     */
    public function getBcc(): array
    {
        return $this->bcc;
    }

    /**
     * Returns the HTML body string.
     *
     * @return string The HTML body string.
     */
    public function getBodyHtml(): string
    {
        return $this->body[static::HTML] ?? '';
    }

    /**
     * Returns the text body string.
     *
     * @return string The text body string.
     */
    public function getBodyText(): string
    {
        return $this->body[static::TEXT] ?? '';
    }

    /**
     * Returns the boundary.
     *
     * @return string The boundary.
     */
    public function getBoundary(): string
    {
        return $this->boundary ??= static::randomString();
    }

    /**
     * Returns the CC addresses.
     *
     * @return array<string, string> The CC addresses.
     */
    public function getCc(): array
    {
        return $this->cc;
    }

    /**
     * Returns the charset.
     *
     * @return string The charset.
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * Returns the email format.
     *
     * @return string The email format.
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Returns the from addresses.
     *
     * @return array<string, string> The from addresses.
     */
    public function getFrom(): array
    {
        return $this->from;
    }

    /**
     * Returns the full email body lines.
     *
     * @return string[] The body lines.
     */
    public function getFullBody(): array
    {
        $contentIds = array_column($this->attachments, 'contentId') |> array_filter(...);

        $hasAttachments = $this->attachments !== [];
        $hasInlineAttachments = count($contentIds) > 0;
        $hasMultipleTypes = $this->format === static::BOTH;
        $multiPart = $hasAttachments || $hasMultipleTypes;

        $lines = [];

        $boundary = $textBoundary = $relatedBoundary = $this->getBoundary();

        if ($hasInlineAttachments) {
            $relatedBoundary = 'rel-'.$boundary;
            $textBoundary = $relatedBoundary;

            $lines[] = '--'.$this->getBoundary();
            $lines[] = 'Content-Type: multipart/related; boundary="'.$relatedBoundary.'"';
            $lines[] = '';
        }

        if ($this->format === static::BOTH && $hasAttachments) {
            $textBoundary = 'alt-boundary';

            $lines[] = '--'.$relatedBoundary;
            $lines[] = 'Content-Type: multipart/alternative; boundary="'.$textBoundary.'"';
            $lines[] = '';
        }

        if (in_array($this->format, [static::TEXT, static::BOTH], true)) {
            if ($multiPart) {
                $lines[] = '--'.$textBoundary;
                $lines[] = 'Content-Type: text/plain; charset='.$this->charset;
                $lines[] = 'Content-Transfer-Encoding: 8bit';
                $lines[] = '';
            }

            $lines = static::prepareBody($this->body[static::TEXT] ?? '', $this->charset, $this->appCharset)
                |> array_merge($lines, ?);
            $lines[] = '';
            $lines[] = '';
        }

        if (in_array($this->format, [static::HTML, static::BOTH], true)) {
            if ($multiPart) {
                $lines[] = '--'.$textBoundary;
                $lines[] = 'Content-Type: text/html; charset='.$this->charset;
                $lines[] = 'Content-Transfer-Encoding: 8bit';
                $lines[] = '';
            }

            $lines = static::prepareBody($this->body[static::HTML] ?? '', $this->charset, $this->appCharset)
                |> array_merge($lines, ?);
            $lines[] = '';
            $lines[] = '';
        }

        if ($textBoundary !== $relatedBoundary) {
            $lines[] = '--'.$textBoundary.'--';
            $lines[] = '';
        }

        if ($hasInlineAttachments) {
            $lines = $this->attachFiles($relatedBoundary, true) |> array_merge($lines, ?);
            $lines[] = '';
            $lines[] = '--'.$relatedBoundary.'--';
            $lines[] = '';
        }

        if ($hasAttachments) {
            $lines = $this->attachFiles($boundary) |> array_merge($lines, ?);
        }

        if ($multiPart) {
            $lines[] = '';
            $lines[] = '--'.$boundary.'--';
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * Returns the full email body string.
     *
     * @return string The body string.
     */
    public function getFullBodyString(): string
    {
        return $this->getFullBody() |> implode("\r\n", ?);
    }

    /**
     * Returns the full email header lines.
     *
     * @param bool $includeBcc Whether to include BCC addresses.
     * @return array<string, int|string|string[]> The email header lines.
     */
    public function getFullHeaders(bool $includeBcc = false): array
    {
        $headers = [];

        $addressHeaders = [
            'From' => 'from',
            'Reply-To' => 'replyTo',
            'Disposition-Notification-To' => 'readReceipt',
            'Return-Path' => 'returnPath',
            'To' => 'to',
            'Cc' => 'cc',
        ];

        foreach ($addressHeaders as $header => $property) {
            if ($this->$property === []) {
                continue;
            }

            $headers[$header] = $this->formatAddresses($this->$property);
        }

        if (array_key_first($this->sender) !== array_key_first($this->from)) {
            $headers['Sender'] = $this->formatAddresses($this->sender);
        }

        $headers['Date'] = date(DATE_RFC2822);
        $headers['Message-ID'] = $this->getMessageId();

        if ($this->priority) {
            $headers['X-Priority'] = $this->priority;
        }

        $headers['Subject'] = static::encodeForHeader($this->subject, $this->charset);
        $headers['MIME-Version'] = '1.0';

        if ($this->attachments !== []) {
            $headers['Content-Type'] = 'multipart/mixed; boundary="'.$this->getBoundary().'"';
        } else if ($this->format === static::BOTH) {
            $headers['Content-Type'] = 'multipart/alternative; boundary="'.$this->getBoundary().'"';
        } else if ($this->format === static::HTML) {
            $headers['Content-Type'] = 'text/html; charset='.$this->charset;
        } else if ($this->format === static::TEXT) {
            $headers['Content-Type'] = 'text/plain; charset='.$this->charset;
        }

        $headers['Content-Transfer-Encoding'] = '8bit';

        $headers = array_merge($headers, $this->headers);

        if ($includeBcc && $this->bcc !== []) {
            $headers['Bcc'] = $this->formatAddresses($this->bcc);
        }

        return $headers;
    }

    /**
     * Returns the full email header string.
     *
     * @return string The email header string.
     */
    public function getFullHeaderString(): string
    {
        $lines = $this->getFullHeaders();

        $headers = [];
        foreach ($lines as $key => $value) {
            if ($value === [] || (!$value && $value !== '0')) {
                continue;
            }

            if (!is_array($value)) {
                $value = [$value];
            }

            foreach ($value as $val) {
                $headers[] = $key.': '.$val;
            }
        }

        return implode("\r\n", $headers);
    }

    /**
     * Returns the additional headers.
     *
     * @return array<string, string|string[]> The additional headers.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Returns the message ID.
     *
     * @return string The message ID.
     */
    public function getMessageId(): string
    {
        return $this->messageId ??= '<'.time().static::randomString().'@'.$this->mailer->getClient().'>';
    }

    /**
     * Returns the priority.
     *
     * @return int|null The priority.
     */
    public function getPriority(): int|null
    {
        return $this->priority;
    }

    /**
     * Returns the read recipient addresses.
     *
     * @return array<string, string> The read recipient addresses.
     */
    public function getReadReceipt(): array
    {
        return $this->readReceipt;
    }

    /**
     * Returns the recipient addresses.
     *
     * @return array<string, string> The recipient addresses.
     */
    public function getRecipients(): array
    {
        $to = $this->getTo();
        $cc = $this->getCc();
        $bcc = $this->getBcc();

        return array_merge($to, $cc, $bcc);
    }

    /**
     * Returns the reply to addresses.
     *
     * @return array<string, string> The reply to addresses.
     */
    public function getReplyTo(): array
    {
        return $this->replyTo;
    }

    /**
     * Returns the return path addresses.
     *
     * @return array<string, string> The return path addresses.
     */
    public function getReturnPath(): array
    {
        return $this->returnPath;
    }

    /**
     * Returns the sender addresses.
     *
     * @return array<string, string> The sender addresses.
     */
    public function getSender(): array
    {
        return $this->sender;
    }

    /**
     * Returns the subject.
     *
     * @return string The subject.
     */
    public function getSubject(): string
    {
        return $this->subject;
    }

    /**
     * Returns the to addresses.
     *
     * @return array<string, string> The to addresses.
     */
    public function getTo(): array
    {
        return $this->to;
    }

    /**
     * Sends the email.
     */
    public function send(): void
    {
        $this->mailer->send($this);
    }

    /**
     * Sets the attachments.
     *
     * @param array<string, array<string, mixed>> $attachments The attachments.
     * @return static The Email instance.
     */
    public function setAttachments(array $attachments): static
    {
        $this->attachments = $attachments;

        return $this;
    }

    /**
     * Sets the BCC address.
     *
     * @param string|string[] $emails The email addresses.
     * @return static The Email instance.
     */
    public function setBcc(array|string $emails): static
    {
        $this->bcc = static::parseEmails($emails);

        return $this;
    }

    /**
     * Sets the body text and/or HTML.
     *
     * @param array<string, string> $body The body text and/or HTML.
     * @return static The Email instance.
     */
    public function setBody(array $body): static
    {
        foreach ($body as $type => $content) {
            $this->body[$type] = $content;
        }

        return $this;
    }

    /**
     * Sets the body HTML.
     *
     * @param string $content The content.
     * @return static The Email instance.
     */
    public function setBodyHtml(string $content): static
    {
        return $this->setBody([static::HTML => $content]);
    }

    /**
     * Sets the body text.
     *
     * @param string $content The content.
     * @return static The Email instance.
     */
    public function setBodyText(string $content): static
    {
        return $this->setBody([static::TEXT => $content]);
    }

    /**
     * Sets the CC address.
     *
     * @param string|string[] $emails The email addresses.
     * @return static The Email instance.
     */
    public function setCc(array|string $emails): static
    {
        $this->cc = static::parseEmails($emails);

        return $this;
    }

    /**
     * Sets the charset.
     *
     * @param string $charset The charset.
     * @return static The Email instance.
     */
    public function setCharset(string $charset): static
    {
        $this->charset = $charset;

        return $this;
    }

    /**
     * Sets the email format.
     *
     * @param string $format The email format.
     * @return static The Email instance.
     *
     * @throws InvalidArgumentException If the format is not valid.
     */
    public function setFormat(string $format): static
    {
        if (!in_array($format, [static::TEXT, static::HTML, static::BOTH], true)) {
            throw new InvalidArgumentException(sprintf(
                'Email format `%s` is not valid.',
                $format
            ));
        }

        $this->format = $format;

        return $this;
    }

    /**
     * Sets the from address.
     *
     * @param string $email The email address.
     * @param string|null $name The name.
     * @return static The Email instance.
     */
    public function setFrom(string $email, string|null $name = null): static
    {
        $this->from = static::parseEmails([$email => $name]);

        return $this;
    }

    /**
     * Sets additional headers.
     *
     * @param array<string, string|string[]> $headers The headers.
     * @return static The Email instance.
     */
    public function setHeaders(array $headers): static
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * Sets the priority.
     *
     * @param int|null $priority The priority.
     * @return static The Email instance.
     *
     * @throws InvalidArgumentException If the priority is not valid.
     */
    public function setPriority(int|null $priority = null): static
    {
        if ($priority !== null && ($priority <= 0 || $priority > 5)) {
            throw new InvalidArgumentException('Email priority must be between 1 and 5.');
        }

        $this->priority = $priority;

        return $this;
    }

    /**
     * Sets the read receipt address.
     *
     * @param string $email The email address.
     * @param string|null $name The name.
     * @return static The Email instance.
     */
    public function setReadReceipt(string $email, string|null $name = null): static
    {
        $this->readReceipt = static::parseEmails([$email => $name]);

        return $this;
    }

    /**
     * Sets the reply to address.
     *
     * @param string|string[] $emails The email addresses.
     * @return static The Email instance.
     */
    public function setReplyTo(array|string $emails): static
    {
        $this->replyTo = static::parseEmails($emails);

        return $this;
    }

    /**
     * Sets the return path address.
     *
     * @param string $email The email address.
     * @param string|null $name The name.
     * @return static The Email instance.
     */
    public function setReturnPath(string $email, string|null $name = null): static
    {
        $this->returnPath = static::parseEmails([$email => $name]);

        return $this;
    }

    /**
     * Sets the sender address.
     *
     * @param string $email The email address.
     * @param string|null $name The name.
     * @return static The Email instance.
     */
    public function setSender(string $email, string|null $name = null): static
    {
        $this->sender = static::parseEmails([$email => $name]);

        return $this;
    }

    /**
     * Sets the subject.
     *
     * @param string $subject The subject.
     * @return static The Email instance.
     */
    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Sets the to addresses.
     *
     * @param string|string[] $emails The email addresses.
     * @return static The Email instance.
     */
    public function setTo(array|string $emails): static
    {
        $this->to = static::parseEmails($emails);

        return $this;
    }

    /**
     * Returns the attached file lines.
     *
     * @param string $boundary The boundary.
     * @param bool $inline Whether to attach inline files.
     * @return string[] The attached file lines.
     *
     * @throws RuntimeException If an attachment is not valid.
     */
    protected function attachFiles(string $boundary, bool $inline = false): array
    {
        $lines = [];

        foreach ($this->attachments as $filename => $attachment) {
            $attachment['contentId'] ??= null;

            if ($inline !== (bool) $attachment['contentId']) {
                continue;
            }

            if ($attachment['contentId']) {
                $attachment['disposition'] ??= 'inline';
            } else {
                $attachment['disposition'] ??= 'attachment';
            }

            $content = $attachment['content'] ?? null;

            if ($content === null && isset($attachment['file'])) {
                $content = @file_get_contents($attachment['file']);
            }

            if (!is_string($content)) {
                throw new RuntimeException(sprintf(
                    'Email attachment `%s` is not valid.',
                    $filename
                ));
            }

            if (!isset($attachment['mimeType'])) {
                $mimeType = new finfo(FILEINFO_MIME_TYPE)->buffer($content);
                $attachment['mimeType'] = $mimeType ?: 'application/octet-stream';
            }

            $content = base64_encode($content) |> chunk_split(...);

            $lines[] = '--'.$boundary;
            $lines[] = 'Content-Type: '.$attachment['mimeType'].'; name="'.$filename.'"';
            $lines[] = 'Content-Disposition: '.$attachment['disposition'];
            $lines[] = 'Content-Transfer-Encoding: base64';

            if ($attachment['contentId']) {
                $lines[] = 'Content-ID: <'.$attachment['contentId'].'>';
            }

            $lines[] = '';
            $lines[] = $content;
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * Returns formatted email addresses.
     *
     * @param array<string, string> $emails The email addresses.
     * @return string The formatted email addresses.
     */
    protected function formatAddresses(array $emails): string
    {
        $emails = array_map(
            function(string $email, string $alias): string {
                if ($email === $alias) {
                    return $email;
                }

                $encodedAlias = static::encodeForHeader($alias, $this->charset);

                if ($alias === $encodedAlias && preg_match('/[^a-z0-9 ]/i', $encodedAlias)) {
                    $encodedAlias = '"'.addcslashes($encodedAlias, '"').'"';
                }

                return $encodedAlias.' <'.$email.'>';
            },
            array_keys($emails),
            $emails
        );

        return implode(', ', $emails);
    }

    /**
     * Encodes MIME header string.
     *
     * @param string $string The string.
     * @param string $charset The charset.
     * @return string The encoded string.
     */
    protected static function encodeForHeader(string $string, string $charset): string
    {
        return mb_encode_mimeheader($string, $charset);
    }

    /**
     * Converts encoding.
     *
     * @param string $string The string.
     * @param string $charsetTo The charset to convert to.
     * @param string|null $charsetFrom The charset to convert from.
     * @return string The encoded string.
     */
    protected static function encodeString(string $string, string $charsetTo, string|null $charsetFrom = null): string
    {
        if ($charsetFrom === $charsetTo) {
            return $string;
        }

        return (string) mb_convert_encoding($string, $charsetTo, $charsetFrom);
    }

    /**
     * Parses email addresses.
     *
     * @param array<int, string>|array<string, string|null>|string $emails The email addresses.
     * @return array<string, string> The parsed email addresses.
     */
    protected static function parseEmails(array|string $emails): array
    {
        if (is_string($emails)) {
            $emails = [$emails];
        }

        $results = [];
        foreach ($emails as $key => $value) {
            if (is_numeric($key)) {
                $key = $value;
                $value = null;
            }

            $key = static::validateEmail($key);

            if (!$key) {
                continue;
            }

            $results[$key] = $value ?? $key;
        }

        return $results;
    }

    /**
     * Encodes, wraps and splits body text into lines.
     *
     * @param string $content The body content.
     * @param string $toCharset The designed charset.
     * @param string $fromCharset The current charset.
     * @return string[] The body text lines.
     */
    protected static function prepareBody(string $content, string $toCharset, string|null $fromCharset): array
    {
        return str_replace(["\r\n", "\r"], "\n", $content)
            |> static::encodeString(?, $toCharset, $fromCharset)
            |> static::wrap(...)
            |> implode("\n", ?)
            |> rtrim(?, "\n")
            |> explode("\n", ?);
    }

    /**
     * Generates a random string.
     *
     * @return string The random string.
     */
    protected static function randomString(): string
    {
        return random_bytes(16) |> md5(...);
    }

    /**
     * Validates an email address.
     *
     * @param string|null $email The email address.
     * @return string|null The validated email address.
     */
    protected static function validateEmail(string|null $email): string|null
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Wraps a string to a character limit.
     *
     * @param string $string The string.
     * @param int $charLimit The character limit.
     * @return string[] The wrapped lines.
     */
    protected static function wrap(string $string, int $charLimit = 998): array
    {
        $lines = str_replace(["\r\n", "\r"], "\n", $string) |> explode("\n", ?);

        $formatted = [];
        foreach ($lines as $line) {
            if (!$line && $line !== '0') {
                $formatted[] = '';

                continue;
            }

            if (strlen($line) <= $charLimit) {
                $formatted[] = $line;

                continue;
            }

            $parts = preg_split('/(<[^>]*>)/', $line, 0, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) ?: [];

            $currentLine = '';
            foreach ($parts as $part) {
                $currentLine ??= '';
                $partLength = strlen($part);

                // if current line will remain below length limit
                if (strlen($currentLine) + $partLength <= $charLimit) {
                    $currentLine .= $part;

                    continue;
                }

                // if html tag, wordwrap the whole line
                if ($part[0] === '<' && $part[$partLength - 1] === '>') {
                    $formatted[] = $currentLine;
                    if ($partLength <= $charLimit) {
                        $currentLine = $part;
                    } else {
                        $formatted[] = $part;
                        $currentLine = null;
                    }

                    continue;
                }

                // wordwrap the line
                $formatted[] = $currentLine;
                $wrappedLines = wordwrap($part, $charLimit) |> explode("\n", ?);
                $currentLine = array_pop($wrappedLines);
                $formatted = array_merge($formatted, $wrappedLines);
            }

            if ($currentLine !== null) {
                $formatted[] = $currentLine;
            }
        }

        return $formatted;
    }
}
