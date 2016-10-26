<?php

namespace Amp\Websocket;

use Amp\{ Internal\Producer, Observable, Observer, Postponed };

/**
 * An API allowing responders to buffer or stream request entity bodies
 *
 * Applications are invoked as soon as headers are received and before
 * entity body data is parsed. The $request->body instance allows
 * applications to await receipt of the full body (buffer) or stream
 * it in chunks as it arrives.
 *
 * Buffering Example:
 *
 *     $responder = function(Request $request, Response $response) {
 *          $bufferedBody = yield $request->getBody();
 *          $response->send("Echoing back the request body: {$bufferedBody}");
 *     };
 *
 * Streaming Example:
 *
 *     $responder = function(Request $request, Response $response) {
 *          $payload = "";
 *          $body = $request->getBody()
 *          while (yield $body->next()) {
 *              $payload .= $body->getCurrent();
 *          }
 *          $response->send("Echoing back the request body: {$payload}");
 *     };
 */
class Message extends Observer implements Observable {
    use Producer;

    private $binary;

    public function __construct(Observable $observable, $binary = null) {
        $this->binary = $binary;

        if (PHP_VERSION_ID >= 70100) {
            $observable->subscribe(\Closure::fromCallable([$this, 'emit']));
        } else {
            $observable->subscribe(function ($value) {
                return $this->emit($value);
            });
        }

        parent::__construct($observable); // DO NOT MOVE - preserve order in which things happen

        $observable->when(function($e) {
            if ($e) {
                $this->fail($e);
                return;
            }

            $result = \implode($this->drain());

            // way to restart, so that even after the success, the next() / getCurrent() API will still work
            $postponed = new Postponed;
            parent::__construct($postponed->getObservable());
            $postponed->emit($result);
            $postponed->resolve();

            $this->resolve($result);
        });
    }

    public function setBinary($binary) {
        if ($this->binary !== null) {
            throw new \Error("A Message can only be set once to either binary or non-binary (i.e. text data)");
        }
        $this->binary = $binary;
    }

    public function isBinary() {
        if ($this->binary === null) {
            throw new \Error("Asking for isBinary() is not possible before the first data of the message or its resolution");
        }
        return $this->binary;
    }
}
