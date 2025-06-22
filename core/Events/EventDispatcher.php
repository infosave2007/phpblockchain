<?php
declare(strict_types=1);

namespace Blockchain\Core\Events;

/**
 * Professional Event Dispatcher
 */
class EventDispatcher
{
    private array $listeners = [];
    
    public function addEventListener(string $eventName, callable $listener): void
    {
        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }
        
        $this->listeners[$eventName][] = $listener;
    }
    
    public function dispatch(string $eventName, array $data = []): void
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }
        
        foreach ($this->listeners[$eventName] as $listener) {
            call_user_func($listener, $data);
        }
    }
}

/**
 * Block Added Event
 */
class BlockAddedEvent
{
    public function __construct(public $block) {}
}
