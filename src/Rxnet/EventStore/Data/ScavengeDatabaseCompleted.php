<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: ClientMessageDtos.proto

namespace Rxnet\EventStore\Data;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Internal\GPBUtil;

/**
 * Protobuf type <code>Rxnet.EventStore.Data.ScavengeDatabaseCompleted</code>
 */
class ScavengeDatabaseCompleted extends \Google\Protobuf\Internal\Message
{
    /**
     * <code>.Rxnet.EventStore.Data.ScavengeDatabaseCompleted.ScavengeResult result = 1;</code>
     */
    private $result = 0;
    /**
     * <code>string error = 2;</code>
     */
    private $error = '';
    /**
     * <code>int32 total_time_ms = 3;</code>
     */
    private $total_time_ms = 0;
    /**
     * <code>int64 total_space_saved = 4;</code>
     */
    private $total_space_saved = 0;

    public function __construct() {
        \GPBMetadata\ClientMessageDtos::initOnce();
        parent::__construct();
    }

    /**
     * <code>.Rxnet.EventStore.Data.ScavengeDatabaseCompleted.ScavengeResult result = 1;</code>
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * <code>.Rxnet.EventStore.Data.ScavengeDatabaseCompleted.ScavengeResult result = 1;</code>
     */
    public function setResult($var)
    {
        GPBUtil::checkEnum($var, \Rxnet\EventStore\Data\ScavengeDatabaseCompleted_ScavengeResult::class);
        $this->result = $var;
    }

    /**
     * <code>string error = 2;</code>
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * <code>string error = 2;</code>
     */
    public function setError($var)
    {
        GPBUtil::checkString($var, True);
        $this->error = $var;
    }

    /**
     * <code>int32 total_time_ms = 3;</code>
     */
    public function getTotalTimeMs()
    {
        return $this->total_time_ms;
    }

    /**
     * <code>int32 total_time_ms = 3;</code>
     */
    public function setTotalTimeMs($var)
    {
        GPBUtil::checkInt32($var);
        $this->total_time_ms = $var;
    }

    /**
     * <code>int64 total_space_saved = 4;</code>
     */
    public function getTotalSpaceSaved()
    {
        return $this->total_space_saved;
    }

    /**
     * <code>int64 total_space_saved = 4;</code>
     */
    public function setTotalSpaceSaved($var)
    {
        GPBUtil::checkInt64($var);
        $this->total_space_saved = $var;
    }

}

