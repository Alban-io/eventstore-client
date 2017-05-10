<?php

namespace Rxnet\EventStore;

use EventLoop\EventLoop;
use Google\Protobuf\Internal\Message;
use React\EventLoop\LoopInterface;
use Rx\Disposable\CallbackDisposable;
use Rx\DisposableInterface;
use Rx\Observable;
use Rx\Observer\CallbackObserver;
use Rx\ObserverInterface;
use Rx\Scheduler\EventLoopScheduler;
use Rx\Subject\ReplaySubject;
use Rx\Subject\Subject;
use Rxnet\Connector\Tcp;
use Rxnet\Dns\Dns;
use Rxnet\Event\ConnectorEvent;
use Rxnet\Event\Event;
use Rxnet\EventStore\Data\ConnectToPersistentSubscription;
use Rxnet\EventStore\Data\PersistentSubscriptionConfirmation;
use Rxnet\EventStore\Data\PersistentSubscriptionStreamEventAppeared;
use Rxnet\EventStore\Data\ReadAllEvents;
use Rxnet\EventStore\Data\ReadEvent;
use Rxnet\EventStore\Data\ReadEventCompleted;
use Rxnet\EventStore\Data\ReadStreamEvents;
use Rxnet\EventStore\Data\ReadStreamEventsCompleted;
use Rxnet\EventStore\Data\ResolvedIndexedEvent;
use Rxnet\EventStore\Data\StreamEventAppeared;
use Rxnet\EventStore\Data\SubscribeToStream;
use Rxnet\EventStore\Data\SubscriptionConfirmation;
use Rxnet\EventStore\Data\SubscriptionDropped;
use Rxnet\EventStore\Data\UnsubscribeFromStream;
use Rxnet\EventStore\Data\WriteEvents;
use Rxnet\EventStore\Message\Credentials;
use Rxnet\EventStore\Message\MessageType;
use Rxnet\EventStore\Message\SocketMessage;
use Rxnet\Transport\Stream;

class EventStore
{
    const POSITION_START = 0;
    const POSITION_END = -1;
    const POSITION_LATEST = 999999;
    /** @var LoopInterface */
    protected $loop;
    /** @var Dns */
    protected $dns;
    /** @var ReadBuffer */
    protected $readBuffer;
    /** @var Writer */
    protected $writer;
    /** @var Stream */
    protected $stream;
    /** @var Tcp */
    protected $connector;
    /** @var Subject */
    protected $connectionSubject;
    /** @var DisposableInterface */
    protected $heartBeatDisposable;
    /** @var  int */
    protected $heartBeatRate;
    /** @var  DisposableInterface */
    protected $readBufferDisposable;

    /**
     * EventStore constructor.
     * @param LoopInterface|null $loop
     * @param Dns|null $dns
     * @param Tcp|null $tcp
     * @param ReadBuffer|null $readBuffer
     * @param Writer|null $writer
     */
    public function __construct(LoopInterface $loop = null, Dns $dns = null, Tcp $tcp = null, ReadBuffer $readBuffer = null, Writer $writer = null)
    {
        $this->loop = $loop ?: EventLoop::getLoop();
        $this->dns = $dns ?: new Dns();
        $this->readBuffer = $readBuffer ?: new ReadBuffer();
        $this->writer = $writer ?: new Writer();
        $this->connector = $tcp ?: new Tcp($this->loop);
    }

    /**
     * @param string $dsn tcp://user:password@host:port
     * @param int $connectTimeout in milliseconds
     * @param int $heartBeatRate in milliseconds
     * @return Observable\AnonymousObservable
     */
    public function connect($dsn = 'tcp://admin:changeit@localhost:1113', $connectTimeout = 1000, $heartBeatRate = 5000)
    {
        // connector compatibility
        $connectTimeout = ($connectTimeout > 0) ? $connectTimeout / 1000 : 0;
        $this->heartBeatRate = $heartBeatRate;


        if (!stristr($dsn, '://')) {
            $dsn = 'tcp://' . $dsn;
        }
        $parsedDsn = parse_url($dsn);
        if (!isset($parsedDsn['host'])) {
            throw new \InvalidArgumentException('Invalid connection DNS given format should be : tcp://user:password@host:port');
        }
        if (!isset($parsedDsn['port'])) {
            $parsedDsn['port'] = 1113;
        }
        if (!isset($parsedDsn['user'])) {
            $parsedDsn['user'] = 'admin';
        }
        if (!isset($parsedDsn['pass'])) {
            $parsedDsn['pass'] = 'changeit';
        }
        // What you should observe if you want to auto reconnect
        $this->connectionSubject = new ReplaySubject(1, 1);
        return Observable::create(function (ObserverInterface $observer) use ($parsedDsn, $connectTimeout) {
            $this->dns
                ->resolve($parsedDsn['host'])
                ->flatMap(
                    function ($ip) use ($parsedDsn, $connectTimeout) {
                        $this->connector->setTimeout($connectTimeout);
                        return $this->connector->connect($ip, $parsedDsn['port']);
                    })
                ->flatMap(function (ConnectorEvent $connectorEvent) use ($parsedDsn) {
                    // send all data to our read buffer
                    $this->stream = $connectorEvent->getStream();
                    $this->readBufferDisposable = $this->stream->subscribe($this->readBuffer);
                    $this->stream->resume();

                    // common object to write to socket
                    $this->writer->setStream($this->stream);
                    $this->writer->setCredentials(new Credentials($parsedDsn['user'], $parsedDsn['pass']));

                    // start heartbeat listener
                    $this->heartBeatDisposable = $this->heartbeat();

                    // Replay subject will do the magic
                    $this->connectionSubject->onNext(new Event('/eventstore/connected'));
                    // Forward internal errors to the connect result
                    return $this->connectionSubject;
                })
                ->subscribe($observer, new EventLoopScheduler($this->loop));

            return new CallbackDisposable(function () {
                if ($this->readBufferDisposable instanceof DisposableInterface) {
                    $this->readBufferDisposable->dispose();
                }
                if ($this->heartBeatDisposable instanceof DisposableInterface) {
                    $this->heartBeatDisposable->dispose();
                }
                if ($this->stream instanceof Stream) {
                    $this->stream->close();
                }
            });
        });
    }

    /**
     * Intercept heartbeat message and answer automatically
     * @return DisposableInterface
     */
    protected function heartbeat()
    {
        return $this->readBuffer
            ->timeout($this->heartBeatRate)
            ->filter(
                function (SocketMessage $message) {
                    return $message->getMessageType()->getType() === MessageType::HEARTBEAT_REQUEST;
                }
            )
            ->subscribe(
                new CallbackObserver(
                    function (SocketMessage $message) {
                        $this->writer->composeAndWriteOnce(MessageType::HEARTBEAT_RESPONSE, null, $message->getCorrelationID());
                    },
                    [$this->connectionSubject, 'onError']
                ),
                new EventLoopScheduler($this->loop)
            );
    }

    /**
     * @param $streamId
     * @param int $expectedVersion
     * @return AppendToStream
     */
    public function appendToStream($streamId, $expectedVersion = -2)
    {
        $writeEvents = new WriteEvents();
        $writeEvents->setEventStreamId($streamId);
        $writeEvents->setRequireMaster(false);
        $writeEvents->setExpectedVersion($expectedVersion);

        return new AppendToStream($writeEvents, $this->writer, $this->readBuffer);
    }

    /**
     * This kind of subscription specifies a starting point, in the form of an event number
     * or transaction file position.
     * The given function will be called for events from the starting point until the end of the stream,
     * and then for subsequently written events.
     *
     * For example, if a starting point of 50 is specified when a stream has 100 events in it,
     * the subscriber can expect to see events 51 through 100, and then any events subsequently
     * written until such time as the subscription is dropped or closed.
     *
     * @param $streamId
     * @param int $startFrom
     * @param bool $resolveLink
     * @return Observable\AnonymousObservable
     */
    public function catchUpSubscription($streamId, $startFrom = self::POSITION_START, $resolveLink = false)
    {
        return $this->readEventsForward($streamId, $startFrom, self::POSITION_LATEST, $resolveLink)
            ->concat($this->volatileSubscription($streamId, $resolveLink));
    }

    /**
     * This kind of subscription calls a given function for events written after
     * the subscription is established.
     *
     * For example, if a stream has 100 events in it when a subscriber connects,
     * the subscriber can expect to see event number 101 onwards until the time
     * the subscription is closed or dropped.
     *
     * @param string $streamId
     * @param bool $resolveLink
     * @return Observable\AnonymousObservable
     */
    public function volatileSubscription($streamId, $resolveLink = false)
    {
        $event = new SubscribeToStream();
        $event->setEventStreamId($streamId);
        $event->setResolveLinkTos($resolveLink);

        return Observable::create(function (ObserverInterface $observer) use ($event) {
            $correlationID = $this->writer->createUUIDIfNeeded();
            $this->writer
                ->composeAndWriteOnce(
                    MessageType::SUBSCRIBE_TO_STREAM,
                    $event,
                    $correlationID
                )
                // When written wait for all responses
                ->concat(
                    $this->readBuffer
                        ->filter(
                            function (SocketMessage $message) use ($correlationID) {
                                // Use same correlationID to pass by this filter
                                return $message->getCorrelationID() == $correlationID;
                            }
                        )
                )
                ->flatMap(
                    function (SocketMessage $message) {
                        $data = $message->getData();
                        //var_dump($data);
                        switch (get_class($data)) {
                            case SubscriptionDropped::class :
                                return Observable::error(new \Exception("Subscription dropped, for reason : {$data->getReason()}"));
                            case SubscriptionConfirmation::class :
                                return Observable::emptyObservable();
                            default :
                                return Observable::just($data);
                        }
                    }
                )
                ->map(
                    function (StreamEventAppeared $eventAppeared) use ($correlationID) {
                        $record = $eventAppeared->getEvent()->getEvent();
                        /* @var \Rxnet\EventStore\Data\EventRecord $record */

                        return new EventRecord(
                            $record,
                            $correlationID,
                            $this->writer
                        );
                    }
                )
                ->subscribe($observer);

            return new CallbackDisposable(function () {
                $event = new UnsubscribeFromStream();
                $this->writer->composeAndWriteOnce(
                    MessageType::UNSUBSCRIBE_FROM_STREAM,
                    $event
                );
            });
        });
    }

    /**
     * @param $streamID
     * @param $group
     * @param int $parallel
     * @return Observable\AnonymousObservable
     */
    public function persistentSubscription($streamID, $group, $parallel = 1)
    {
        $query = new ConnectToPersistentSubscription();
        $query->setEventStreamId($streamID);
        $query->setSubscriptionId($group);
        $query->setAllowedInFlightMessages($parallel);

        return Observable::create(function (ObserverInterface $observer) use ($query, $group) {
            $correlationID = $this->writer->createUUIDIfNeeded();
            $this->writer
                ->composeAndWriteOnce(
                    MessageType::CONNECT_TO_PERSISTENT_SUBSCRIPTION,
                    $query,
                    $correlationID
                )
                // When written wait for all responses
                ->concat(
                    $this->readBuffer
                        ->filter(
                            function (SocketMessage $message) use ($correlationID) {
                                // Use same correlationID to pass by this filter
                                return $message->getCorrelationID() == $correlationID;
                            }
                        )
                )
                ->flatMap(
                    function (SocketMessage $message) {
                        $data = $message->getData();
                        //var_dump($data);
                        switch (get_class($data)) {
                            case SubscriptionDropped::class :
                                return Observable::error(new \Exception("Subscription dropped, for reason : {$data->getReason()}"));
                            case PersistentSubscriptionConfirmation::class :
                                return Observable::emptyObservable();
                            case PersistentSubscriptionStreamEventAppeared::class :
                                return Observable::just($data);
                            default:
                                var_dump($data);
                        }
                    }
                )
                ->map(
                    function (PersistentSubscriptionStreamEventAppeared $eventAppeared) use ($correlationID, $group) {
                        $record = $eventAppeared->getEvent()->getEvent();
                        //$link = $eventAppeared->getEvent()->getLink();
                        /* @var \Rxnet\EventStore\Data\EventRecord $record */

                        return new AcknowledgeableEventRecord(
                            $record,
                            $correlationID,
                            $group,
                            $this->writer
                        );
                    }
                )
                ->subscribe($observer);

            return new CallbackDisposable(function () {
                $event = new UnsubscribeFromStream();
                $this->writer->composeAndWriteOnce(
                    MessageType::UNSUBSCRIBE_FROM_STREAM,
                    $event
                );
            });
        });
    }

    /**
     * @param $streamId
     * @param int $number
     * @param bool $resolveLinkTos
     * @return Observable\AnonymousObservable
     */
    public function readEvent($streamId, $number = 0, $resolveLinkTos = false)
    {
        $event = new ReadEvent();
        $event->setEventStreamId($streamId);
        $event->setEventNumber($number);
        $event->setResolveLinkTos($resolveLinkTos);
        $event->setRequireMaster(false);

        $correlationID = $this->writer->createUUIDIfNeeded();
        return $this->writer->composeAndWriteOnce(MessageType::READ, $event, $correlationID)
            ->concat(
                $this->readBuffer
                    ->filter(
                        function (SocketMessage $message) use ($correlationID) {
                            // Use same correlationID to pass by this filter
                            return $message->getCorrelationID() == $correlationID;
                        }
                    )
            )
            ->take(1)
            ->map(function (SocketMessage $message) {
                $data = $message->getData();
                /* @var  ReadEventCompleted $data */
                return new EventRecord($data->getEvent()->getEvent());
            });
    }

    /**
     * @param bool $resolveLinkTos
     * @return Observable\AnonymousObservable
     */
    public function readAllEvents($resolveLinkTos = false)
    {
        $query = new ReadAllEvents();
        $query->setRequireMaster(false);
        $query->setResolveLinkTos($resolveLinkTos);

        return $this->readEvents($query, MessageType::READ_ALL_EVENTS_FORWARD);
    }

    /**
     * @param $streamId
     * @param int $fromEvent
     * @param int $max
     * @param bool $resolveLinkTos
     * @return Observable\AnonymousObservable
     */
    public function readEventsForward($streamId, $fromEvent = self::POSITION_START, $max = self::POSITION_LATEST, $resolveLinkTos = false)
    {
        $query = new ReadStreamEvents();
        $query->setRequireMaster(false);
        $query->setEventStreamId($streamId);
        $query->setFromEventNumber($fromEvent);
        $query->setMaxCount($max);
        $query->setResolveLinkTos($resolveLinkTos);

        return $this->readEvents($query, MessageType::READ_STREAM_EVENTS_FORWARD);
    }

    /**
     * @param $streamId
     * @param int $fromEvent
     * @param int $max
     * @param bool $resolveLinkTos
     * @return Observable\AnonymousObservable
     */
    public function readEventsBackward($streamId, $fromEvent = self::POSITION_END, $max = 10, $resolveLinkTos = false)
    {
        $query = new ReadStreamEvents();
        $query->setRequireMaster(false);
        $query->setEventStreamId($streamId);
        $query->setFromEventNumber($fromEvent);
        $query->setMaxCount($max);
        $query->setResolveLinkTos($resolveLinkTos);

        return $this->readEvents($query, MessageType::READ_STREAM_EVENTS_BACKWARD);
    }

    /**
     * Helper to read all events, repeat query until end reached
     * @param Message $query
     * @param int $messageType
     * @return Observable\AnonymousObservable
     */
    protected function readEvents(Message $query, $messageType)
    {
        $end = false;
        $maxPossible = 10; //4096
        $max = ($query instanceof ReadStreamEvents) ? $query->getMaxCount() : self::POSITION_LATEST;

        $asked = $max;
        if ($max >= $maxPossible) {
            $max = $maxPossible;
            $query->setMaxCount($max);
        }

        $correlationID = $this->writer->createUUIDIfNeeded();

        // TODO backpressure, wait for event's array to be read before reading next
        // OnDemand ? onBackpressureBuffer ?
        return $this->writer
            // First query
            ->composeAndWriteOnce($messageType, $query, $correlationID)
            // When written wait for all responses
            ->concat(
                $this->readBuffer
                    ->filter(
                        function (SocketMessage $message) use ($correlationID) {
                            // Use same correlationID to pass by this filter
                            return $message->getCorrelationID() == $correlationID;
                        }
                    )
            )
            // Throw if we have an error message
            ->flatMap(function (SocketMessage $message) {
                $event = $message->getData();
                /* @var ReadStreamEventsCompleted $event */
                if ($error = $event->getError()) {
                    return Observable::error(new \Exception($error));
                }
                return Observable::just($message);
            })
            // If more data is needed do another query
            ->doOnNext(function (SocketMessage $message) use ($query, $correlationID, &$end, &$asked, $max, $maxPossible, $messageType) {
                $event = $message->getData();
                /* @var ReadStreamEventsCompleted $event */
                $records = $event->getEvents();
                $asked -= count($records);
                if ($event->getIsEndOfStream()) {
                    $end = true;
                } elseif ($asked <= 0 && $max != self::POSITION_LATEST) {
                    $end = true;
                }
                if (!$end) {
                    $start = $records[count($records) - 1];
                    /* @var ResolvedIndexedEvent $start */
                    $start = ($messageType == MessageType::READ_STREAM_EVENTS_FORWARD) ? $start->getEvent()->getEventNumber() + 1 : $start->getEvent()->getEventNumber() - 1;
                    $query->setFromEventNumber($start);
                    $query->setMaxCount($asked > $maxPossible ? $maxPossible : $asked);

                    //echo "Not end of stream need slice from position {$start} next is {$event->getNextEventNumber()} \n";
                    $this->writer->composeAndWriteOnce(
                        $messageType,
                        $query,
                        $correlationID
                    );
                }
            })
            // Continue to watch until we have all our results (or end)
            ->takeWhile(function () use (&$end) {
                return !$end;
            })
            // Format EventRecord for easy reading
            ->flatMap(function (SocketMessage $message) use (&$asked, &$end) {
                $event = $message->getData();
                /* @var ReadStreamEventsCompleted $event */
                $records = [];
                /* @var \Rxnet\EventStore\EventRecord[] $records */
                $events = $event->getEvents();
                foreach ($events as $item) {
                    /* @var \Rxnet\EventStore\Data\ResolvedIndexedEvent $item */
                    $records[] = new EventRecord($item->getEvent());
                }
                // Will emit onNext for each event
                return Observable::fromArray($records);
            });

    }
}