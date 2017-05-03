Event Store Client
==================

Asynchronous client for [EventStore](https://geteventstore.com/) TCP Api



## Usage
### Connect
```php
<?php
$eventStore = new \Rxnet\EventStore\EventStore();
// Default value
$eventStore->connect('tcp://admin:changeit@localhost:1113');

$eventStore = new \Rxnet\EventStore\EventStore();
// Lazy way, to connect
$eventStore = \Rxnet\await($eventStore->connect());
/* @var \Rxnet\EventStore\EventStore $eventStore */
echo "connected \n";
```

### Write
You can put as many event you want (max 2000) before commit or commit after each

```php
<?php
$eventStore->appendToStream('category-test_stream_id')
    ->jsonEvent('event_type', ['data' => microtime()], ['worker'=>'ip'])
    ->jsonEvent('event_type2', ['data' => microtime()], ['some'=>'metadata'])
    ->event('event_type3', microtime(), 'my meta data')
    // Nothing is written until commit
    ->commit()
    ->subscribeCallback(function(\Rxnet\EventStore\Data\WriteEventsCompleted $eventsCompleted) {
        echo "Last event number {$eventsCompleted->getLastEventNumber()} on commit position {$eventsCompleted->getCommitPosition()} \n";
    });
```
### Subscription

Connect to persistent subscription $ce-category (projection) has group my-group, process message 4 by 4, then acknowledge or not
```php
<?php
$eventStore->persistentSubscription('$ce-category', 'my-group', 4)
    ->subscribeCallback(function(\Rxnet\EventStore\AcknowledgeableEventRecord $event) {
        echo "received {$event->getId()} event {$event->getType()} ({$event->getNumber()}) with id {$event->getId()} on {$event->getStreamId()} \n";
        if($event->getNumber() %2) {
            $event->ack();
        }
        else {
            $event->nack($event::NACK_ACTION_RETRY, 'Explain why');
        }
    });
```

Watch given stream for new events.  
SubscribeCallback will be called when a new event appeared

```php
<?php
$eventStore->volatileSubscription('category-test_stream_id')
    ->subscribeCallback(function(\Rxnet\EventStore\EventRecord $event) {
        echo "received {$event->getId()} event {$event->getType()} ({$event->getNumber()}) with id {$event->getId()} on {$event->getStreamId()} \n";
    });
```

Read all events from position 100, when everything is read, watch for new events (like volatile)
```php
<?php
$eventStore->catchUpSubscription('category-test_stream_id', 100)
    ->subscribeCallback(function(\Rxnet\EventStore\EventRecord $event) {
        echo "received {$event->getId()} event {$event->getType()} ({$event->getNumber()}) with id {$event->getId()} on {$event->getStreamId()} \n";
    });
```

### Read

Read from event 0 to event 100 on stream category-test_stream_id then end
```php
<?php
$eventStore->readEventsForward('category-test_stream_id', 0, 100)
    ->subscribeCallback(function(\Rxnet\EventStore\EventRecord $event) {
        echo "received {$event->getId()} event {$event->getType()} ({$event->getNumber()}) with id {$event->getId()} on {$event->getStreamId()} \n";
    });
```

Read backward (latest to oldest) from event 100 to event 90 on stream category-test_stream_id then end
```php
<?php
$eventStore->readEventsBackWard('category-test_stream_id', 100, 10)
    ->subscribeCallback(function(\Rxnet\EventStore\EventRecord $event) {
        echo "received {$event->getId()} event {$event->getType()} ({$event->getNumber()}) with id {$event->getId()} on {$event->getStreamId()} \n";
    });
```

Read first event detail from category-test_stream_id
```php
<?php
$eventStore->readEvent('category-test_stream_id', 0)
    ->subscribeCallback(function(\Rxnet\EventStore\EventRecord $event) {
        echo "received {$event->getId()} event {$event->getType()} ({$event->getNumber()}) with id {$event->getId()} on {$event->getStreamId()} \n";
    });
```


## Contribute
### TODO

- [ ] Append event to stream
- [ ] Read given stream
- [ ] subscribe to given stream
- [ ] read a huge stream
- [ ] persistent subscription
- [ ] transactions
- [ ] write som specs
- [ ] create / update / delete persistent subscription
- [ ] delete stream


### Protocol buffer
If ClientMessageDtos.proto is modified, you must generate new php class
```bash
./vendor/bin/protobuf --include-descriptors -i . -o ./src ./ClientMessageDtos.proto
```
