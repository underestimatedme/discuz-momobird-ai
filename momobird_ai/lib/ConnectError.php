<?php
/**
 * Any failed call to the MomoBird connect API. The message and code are
 * redacted when the error is built, so no caller can forget to.
 */
final class MomoBird_ConnectError extends RuntimeException
{
    const NETWORK = 'NETWORK';
    const HTTP = 'HTTP';
    const PLAN_REQUIRED = 'PLAN_REQUIRED';
    const UNAUTHORIZED = 'UNAUTHORIZED';
    const DISABLED = 'DISABLED';
    const WIDGET_NOT_READY = 'WIDGET_NOT_READY';
    const TOO_LARGE = 'TOO_LARGE';
    const REJECTED = 'REJECTED';

    private $kind;
    private $status;
    private $apiCode;

    public function __construct(string $kind, string $message, int $status = 0, string $code = '', string $key = '')
    {
        parent::__construct(MomoBird_Redactor::clean($message, $key), $status);
        $this->kind = $kind;
        $this->status = $status;
        $this->apiCode = MomoBird_Redactor::clean($code, $key);
    }

    /** One of the kind constants. */
    public function kind(): string
    {
        return $this->kind;
    }

    /** HTTP status, 0 when there was no HTTP response. */
    public function status(): int
    {
        return $this->status;
    }

    /** The API's error code (or http_NNN / a client-side code such as redirect). */
    public function code(): string
    {
        return $this->apiCode;
    }
}
