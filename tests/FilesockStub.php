<?php

/**
 * Stand-in for Discuz!'s `filesock` (source/class/class_filesock.php), only
 * what MomoBird_FilesockTransport touches. Property names and the
 * `request()`-returns-body-only contract follow filesock_base / filesock_curl.
 */
class MomoBird_FilesockStubInstance
{
    public $safequery = true;
    public $errno = 0;
    public $errstr = '';
    public $curlstatus = array('http_code' => 200);
    public $body = '';
    public $throw = null;

    public function request()
    {
        if ($this->throw) {
            throw $this->throw;
        }
        return $this->errno ? null : $this->body;
    }
}

class filesock
{
    public static $opened = array();
    /** @var callable|null receives the open() param, returns a MomoBird_FilesockStubInstance */
    public static $factory;

    public static function open($param = array())
    {
        self::$opened[] = $param;
        $instance = new MomoBird_FilesockStubInstance();
        if (self::$factory) {
            call_user_func(self::$factory, $instance, $param);
        }
        return $instance;
    }
}
