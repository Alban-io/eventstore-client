<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: ClientMessageDtos.proto

namespace Rxnet\EventStore\Data;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Internal\GPBUtil;

/**
 * Protobuf type <code>Rxnet.EventStore.Data.WriteEventsCompleted</code>
 */
class WriteEventsCompleted extends \Google\Protobuf\Internal\Message
{
    /**
     * <code>.Rxnet.EventStore.Data.OperationResult result = 1;</code>
     */
    private $result = 0;
    /**
     * <code>string message = 2;</code>
     */
    private $message = '';
    /**
     * <code>int32 first_event_number = 3;</code>
     */
    private $first_event_number = 0;
    /**
     * <code>int32 last_event_number = 4;</code>
     */
    private $last_event_number = 0;
    /**
     * <code>int64 prepare_position = 5;</code>
     */
    private $prepare_position = 0;
    /**
     * <code>int64 commit_position = 6;</code>
     */
    private $commit_position = 0;

    public function __construct() {
        \GPBMetadata\ClientMessageDtos::initOnce();
        parent::__construct();
    }

    /**
     * <code>.Rxnet.EventStore.Data.OperationResult result = 1;</code>
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * <code>.Rxnet.EventStore.Data.OperationResult result = 1;</code>
     */
    public function setResult($var)
    {
        GPBUtil::checkEnum($var, \Rxnet\EventStore\Data\OperationResult::class);
        $this->result = $var;
    }

    /**
     * <code>string message = 2;</code>
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * <code>string message = 2;</code>
     */
    public function setMessage($var)
    {
        GPBUtil::checkString($var, True);
        $this->message = $var;
    }

    /**
     * <code>int32 first_event_number = 3;</code>
     */
    public function getFirstEventNumber()
    {
        return $this->first_event_number;
    }

    /**
     * <code>int32 first_event_number = 3;</code>
     */
    public function setFirstEventNumber($var)
    {
        GPBUtil::checkInt32($var);
        $this->first_event_number = $var;
    }

    /**
     * <code>int32 last_event_number = 4;</code>
     */
    public function getLastEventNumber()
    {
        return $this->last_event_number;
    }

    /**
     * <code>int32 last_event_number = 4;</code>
     */
    public function setLastEventNumber($var)
    {
        GPBUtil::checkInt32($var);
        $this->last_event_number = $var;
    }

    /**
     * <code>int64 prepare_position = 5;</code>
     */
    public function getPreparePosition()
    {
        return $this->prepare_position;
    }

    /**
     * <code>int64 prepare_position = 5;</code>
     */
    public function setPreparePosition($var)
    {
        GPBUtil::checkInt64($var);
        $this->prepare_position = $var;
    }

    /**
     * <code>int64 commit_position = 6;</code>
     */
    public function getCommitPosition()
    {
        return $this->commit_position;
    }

    /**
     * <code>int64 commit_position = 6;</code>
     */
    public function setCommitPosition($var)
    {
        GPBUtil::checkInt64($var);
        $this->commit_position = $var;
    }

}

